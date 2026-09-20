<?php

namespace Tests\Feature\Resellers;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Jobs\SendResellerBroadcastMessage;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * v3.0.6 — «پنل نمایندگان هم نیاز به پیام همگانی دارد».
 *
 * مهم‌ترین چیزی که این تست‌ها محافظت می‌کنند «اصل طلایی جداسازی داده»
 * است: یک نماینده تحت هیچ شرایطی نباید بتواند به مشتریِ نماینده‌ی دیگر
 * یا به مشتری مستقیم Core پیام بفرستد.
 */
class ResellerBroadcastTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;
    use StoreMembers;

    #[Test]
    public function a_reseller_broadcast_only_reaches_that_resellers_own_customers(): void
    {
        $resellerA = Reseller::factory()->create(['bot_token' => 'token-a']);
        $resellerB = Reseller::factory()->create(['bot_token' => 'token-b']);

        $mine = $this->memberOf($resellerA, ['telegram_id' => 1001]);

        $theirs = $this->memberOf($resellerB, ['telegram_id' => 2002]);

        $coreCustomer = User::factory()->create(['telegram_id' => 3003]);

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === $mine->telegram_id
                && $params['text'] === 'سلام'
            )
            ->andReturn($this->fakeMessage());

        $factory = Mockery::mock(ResellerApiFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withArgs(fn (Reseller $reseller) => $reseller->is($resellerA))
            ->andReturn($telegram);

        (new SendResellerBroadcastMessage($resellerA, 'سلام'))
            ->handle($factory);

        $this->assertNotSame($mine->telegram_id, $theirs->telegram_id);
        $this->assertNotSame($mine->telegram_id, $coreCustomer->telegram_id);
    }

    #[Test]
    public function a_reseller_broadcast_is_sent_with_that_resellers_own_bot_token(): void
    {
        $reseller = Reseller::factory()->create(['bot_token' => 'token-a']);

        $this->memberOf($reseller, ['telegram_id' => 1001]);

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 1001
                && $params['text'] === 'سلام'
            )
            ->andReturn($this->fakeMessage());

        $factory = Mockery::mock(ResellerApiFactory::class);

        $factory->shouldReceive('make')
            ->once()
            ->withArgs(fn (Reseller $received) => $received->is($reseller)
                && $received->bot_token === 'token-a'
            )
            ->andReturn($telegram);

        (new SendResellerBroadcastMessage($reseller, 'سلام'))
            ->handle($factory);
    }

    #[Test]
    public function a_reseller_without_a_bot_token_sends_nothing_instead_of_crashing(): void
    {
        $reseller = Reseller::factory()->create(['bot_token' => null]);

        $this->memberOf($reseller, ['telegram_id' => 1001]);

        $factory = Mockery::mock(ResellerApiFactory::class);

        $factory->shouldNotReceive('make');

        (new SendResellerBroadcastMessage($reseller, 'سلام'))
            ->handle($factory);

        $this->assertTrue(true, 'No Telegram API should be created without a reseller bot token.');
    }

    #[Test]
    public function customers_without_a_telegram_id_are_skipped(): void
    {
        $reseller = Reseller::factory()->create(['bot_token' => 'token-a']);

        $this->memberOf($reseller, ['telegram_id' => null]);

        $factory = Mockery::mock(ResellerApiFactory::class);

        $factory->shouldReceive('make')
            ->once()
            ->andReturn(Mockery::mock(Api::class));

        (new SendResellerBroadcastMessage($reseller, 'سلام'))
            ->handle($factory);
    }
}
