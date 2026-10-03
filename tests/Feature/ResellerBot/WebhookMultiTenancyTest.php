<?php

namespace Tests\Feature\ResellerBot;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\ResellerConversationState;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
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

    /** @var array<int, array> پیام‌های ارسال‌شده توسط ربات (برای اطمینان از متن) */
    protected array $sent = [];

    protected function fakeApiFactory(): Api
    {
        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                $this->sent[] = $params;

                return new Message([
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'],
                    'text' => $params['text'] ?? '',
                ]);
            });
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(
            new \Telegram\Bot\Objects\User(['id' => 1, 'is_bot' => true, 'first_name' => 'Shop', 'username' => 'shop_bot'])
        );
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();

        $factory = Mockery::mock(ResellerApiFactory::class);
        $factory->shouldReceive('make')->andReturn($telegram);
        $this->app->instance(ResellerApiFactory::class, $factory);

        return $telegram;
    }

    /**
     * وب‌هوک نماینده Fail-closed است (فاز ۸): هر درخواست باید secret درست را
     * همراه داشته باشد، پس تست‌ها همان رفتار تلگرام را شبیه‌سازی می‌کنند.
     */
    protected function postWebhook(Reseller $reseller, array $update): TestResponse
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $reseller->ensureWebhookSecret())
            ->postJson("/reseller-bot/webhook/{$reseller->webhook_slug}", $update);
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

    #[Test]
    public function a_message_to_resellers_webhook_slug_is_scoped_to_that_reseller_only(): void
    {
        $this->fakeApiFactory();

        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $this->postWebhook($resellerA, $this->startUpdate(111111))
            ->assertOk();

        $user = User::query()->where('telegram_id', 111111)->firstOrFail();

        // عضویت = CustomerAccount در فروشگاه A (نه users.reseller_id)
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'store_type' => 'reseller', 'reseller_id' => $resellerA->id, 'status' => 'active',
        ]);
        $this->assertDatabaseMissing('customer_accounts', ['user_id' => $user->id, 'reseller_id' => $resellerB->id]);
        $this->assertDatabaseHas('reseller_conversation_states', [
            'reseller_id' => $resellerA->id,
            'telegram_chat_id' => 111111,
        ]);
        $this->assertDatabaseMissing('reseller_conversation_states', [
            'reseller_id' => $resellerB->id,
            'telegram_chat_id' => 111111,
        ]);
    }

    #[Test]
    public function a_disabled_membership_is_told_so_and_is_not_silently_reactivated(): void
    {
        $this->fakeApiFactory();

        $reseller = Reseller::factory()->create();
        $telegramId = 555555;

        $this->postWebhook($reseller, $this->startUpdate($telegramId))->assertOk();

        $user = User::query()->where('telegram_id', $telegramId)->firstOrFail();
        app(ResellerCustomerService::class)->remove($reseller, $user);

        $this->sent = [];
        $this->postWebhook($reseller, $this->startUpdate($telegramId))->assertOk();

        $this->assertStringContainsString('غیرفعال', $this->sent[0]['text']);
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'reseller_id' => $reseller->id, 'status' => 'disabled',
        ]);
    }

    #[Test]
    public function an_unknown_webhook_slug_returns_404(): void
    {
        $this->fakeApiFactory();

        $this->postJson('/reseller-bot/webhook/does-not-exist', $this->startUpdate(222222))
            ->assertNotFound();
    }

    #[Test]
    public function the_same_telegram_user_can_be_a_customer_of_two_reseller_bots_with_independent_state_and_wallets(): void
    {
        $this->fakeApiFactory();

        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        // یک کاربر (چون در چت خصوصی chat_id == from.id) با هر دو ربات صحبت می‌کند
        $telegramId = 333333;

        $this->postWebhook($resellerA, $this->startUpdate($telegramId))->assertOk();

        // Rule 12: مشتری نماینده‌ی A بودن مانع مشتری B شدن نیست
        $this->postWebhook($resellerB, $this->startUpdate($telegramId))->assertOk();

        $user = User::query()->where('telegram_id', $telegramId)->firstOrFail();

        $this->assertEquals(2, CustomerAccount::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('customer_accounts', ['user_id' => $user->id, 'reseller_id' => $resellerA->id, 'status' => 'active']);
        $this->assertDatabaseHas('customer_accounts', ['user_id' => $user->id, 'reseller_id' => $resellerB->id, 'status' => 'active']);

        // Walletها مستقل‌اند
        $wallets = app(WalletService::class);
        $accountA = CustomerAccount::where('user_id', $user->id)->where('reseller_id', $resellerA->id)->firstOrFail();
        $wallets->credit($accountA, 50);
        $this->assertEquals(50, $wallets->balanceIn($user, StoreContext::reseller($resellerA)));
        $this->assertEquals(0, $wallets->balanceIn($user, StoreContext::reseller($resellerB)));
        $this->assertEquals(0, $wallets->balanceIn($user, StoreContext::main()));

        // ولی state مکالمه‌اش برای هر دو نماینده جداگانه ساخته شده (تداخل رخ نداده)
        $this->assertDatabaseHas('reseller_conversation_states', ['reseller_id' => $resellerA->id, 'telegram_chat_id' => $telegramId]);
        $this->assertDatabaseHas('reseller_conversation_states', ['reseller_id' => $resellerB->id, 'telegram_chat_id' => $telegramId]);
    }

    #[Test]
    public function duplicate_telegram_update_id_is_processed_only_once(): void
    {
        $this->fakeApiFactory();
        $reseller = Reseller::factory()->create();
        $update = $this->startUpdate(444444);

        $this->postWebhook($reseller, $update)->assertOk();
        $countAfterFirst = ResellerConversationState::query()->count();

        // همان update_id دوباره ارسال می‌شود (شبیه‌سازی retry تلگرام)
        $this->postWebhook($reseller, $update)->assertOk();

        $this->assertEquals($countAfterFirst, ResellerConversationState::query()->count());
    }

    #[Test]
    public function echoed_bot_messages_are_never_processed_as_a_real_user(): void
    {
        $this->fakeApiFactory();
        $reseller = Reseller::factory()->create();

        $update = $this->startUpdate(555555);
        $update['message']['from']['is_bot'] = true;

        $this->postWebhook($reseller, $update)->assertOk();

        $this->assertDatabaseMissing('users', ['telegram_id' => 555555]);
    }
}
