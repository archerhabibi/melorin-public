<?php

namespace Tests\Feature\ResellerBot;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Models\Reseller;
use App\Models\ResellerConversationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

/**
 * پوشش دقیقاً همان چیزی که ریسک اصلیِ معماریِ چندمستأجریِ وب‌هوک بود:
 * دو نماینده‌ی مختلف، دو bot_token مختلف، و این‌که یک درخواست هرگز به
 * نماینده‌ی اشتباه نرسد یا Api::class اشتباه resolve نشود. برخلاف بقیه‌ی
 * تست‌های ربات (که مستقیم Handler را صدا می‌زنند)، این تست عمداً از
 * مسیر HTTP واقعیِ route عبور می‌کند تا خودِ WebhookController را هم
 * پوشش دهد.
 */
class WebhookMultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeApiFactory(): Api
    {
        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (array $params) => new Message([
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'],
                'text' => $params['text'] ?? '',
            ]));
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(
            new \Telegram\Bot\Objects\User(['id' => 1, 'is_bot' => true, 'first_name' => 'Shop', 'username' => 'shop_bot'])
        );
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();

        $factory = Mockery::mock(ResellerApiFactory::class);
        $factory->shouldReceive('make')->andReturn($telegram);
        $this->app->instance(ResellerApiFactory::class, $factory);

        return $telegram;
    }

    protected function startUpdate(int $telegramId, string $firstName = 'Tester'): array
    {
        return [
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => $firstName],
                'text' => '/start',
            ],
        ];
    }

    /** @test */
    public function a_message_to_resellers_webhook_slug_is_scoped_to_that_reseller_only(): void
    {
        $this->fakeApiFactory();

        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $this->postJson("/reseller-bot/webhook/{$resellerA->webhook_slug}", $this->startUpdate(111111))
            ->assertOk();

        $user = User::query()->where('telegram_id', 111111)->firstOrFail();

        $this->assertEquals($resellerA->id, $user->reseller_id);
        $this->assertDatabaseHas('reseller_conversation_states', [
            'reseller_id' => $resellerA->id,
            'telegram_chat_id' => 111111,
        ]);
        $this->assertDatabaseMissing('reseller_conversation_states', [
            'reseller_id' => $resellerB->id,
            'telegram_chat_id' => 111111,
        ]);
    }

    /** @test */
    public function an_unknown_webhook_slug_returns_404(): void
    {
        $this->fakeApiFactory();

        $this->postJson('/reseller-bot/webhook/does-not-exist', $this->startUpdate(222222))
            ->assertNotFound();
    }

    /** @test */
    public function the_same_telegram_user_talking_to_two_different_reseller_bots_gets_independent_conversation_state(): void
    {
        $this->fakeApiFactory();

        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        // یک کاربر (چون در چت خصوصی chat_id == from.id) با هر دو ربات صحبت می‌کند
        $telegramId = 333333;

        $this->postJson("/reseller-bot/webhook/{$resellerA->webhook_slug}", $this->startUpdate($telegramId))->assertOk();

        // در این طراحی، این کاربر از قبل مشتری نماینده‌ی A است، پس ربات B باید او را رد کند
        $this->postJson("/reseller-bot/webhook/{$resellerB->webhook_slug}", $this->startUpdate($telegramId))->assertOk();

        $user = User::query()->where('telegram_id', $telegramId)->firstOrFail();
        $this->assertEquals($resellerA->id, $user->reseller_id);

        // ولی state مکالمه‌اش برای هر دو نماینده جداگانه ساخته شده (تداخل رخ نداده)
        $this->assertDatabaseHas('reseller_conversation_states', ['reseller_id' => $resellerA->id, 'telegram_chat_id' => $telegramId]);
        $this->assertDatabaseHas('reseller_conversation_states', ['reseller_id' => $resellerB->id, 'telegram_chat_id' => $telegramId]);
    }

    /** @test */
    public function duplicate_telegram_update_id_is_processed_only_once(): void
    {
        $this->fakeApiFactory();
        $reseller = Reseller::factory()->create();
        $update = $this->startUpdate(444444);

        $this->postJson("/reseller-bot/webhook/{$reseller->webhook_slug}", $update)->assertOk();
        $countAfterFirst = ResellerConversationState::query()->count();

        // همان update_id دوباره ارسال می‌شود (شبیه‌سازی retry تلگرام)
        $this->postJson("/reseller-bot/webhook/{$reseller->webhook_slug}", $update)->assertOk();

        $this->assertEquals($countAfterFirst, ResellerConversationState::query()->count());
    }

    /** @test */
    public function echoed_bot_messages_are_never_processed_as_a_real_user(): void
    {
        $this->fakeApiFactory();
        $reseller = Reseller::factory()->create();

        $update = $this->startUpdate(555555);
        $update['message']['from']['is_bot'] = true;

        $this->postJson("/reseller-bot/webhook/{$reseller->webhook_slug}", $update)->assertOk();

        $this->assertDatabaseMissing('users', ['telegram_id' => 555555]);
    }
}
