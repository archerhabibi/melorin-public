<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletService::class);
    }

    #[Test]
    public function it_creates_a_wallet_with_zero_balance_on_first_access(): void
    {
        $user = User::factory()->create();

        $this->assertEquals(0.0, $this->wallet->balance($user));
    }

    #[Test]
    public function charging_increases_balance_and_records_a_transaction(): void
    {
        $user = User::factory()->create();

        $tx = $this->wallet->charge($user, 100000, description: 'شارژ آزمایشی');

        $this->assertEquals(100000, $this->wallet->balance($user));
        $this->assertEquals('charge', $tx->type);
        $this->assertEquals(100000, $tx->balance_after);
    }

    #[Test]
    public function purchase_deducts_balance_when_sufficient(): void
    {
        $user = User::factory()->create();
        $this->wallet->charge($user, 200000);

        $this->wallet->purchase($user, 150000, description: 'خرید آزمایشی');

        $this->assertEquals(50000, $this->wallet->balance($user));
    }

    #[Test]
    public function purchase_throws_when_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $this->wallet->charge($user, 10000);

        $this->expectException(InsufficientBalanceException::class);

        $this->wallet->purchase($user, 50000);
    }

    #[Test]
    public function refund_increases_balance_again(): void
    {
        $user = User::factory()->create();
        $this->wallet->charge($user, 100000);
        $this->wallet->purchase($user, 60000);

        $this->wallet->refund($user, 60000, description: 'بازگشت وجه سفارش ناموفق');

        $this->assertEquals(100000, $this->wallet->balance($user));
    }

    #[Test]
    public function admin_adjust_can_go_negative_amount_but_never_below_zero_total(): void
    {
        $user = User::factory()->create();
        $this->wallet->charge($user, 50000);

        $this->wallet->adminAdjust($user, -20000, description: 'تنظیم دستی ادمین');

        $this->assertEquals(30000, $this->wallet->balance($user));

        $this->expectException(InsufficientBalanceException::class);
        $this->wallet->adminAdjust($user, -1000000);
    }

    #[Test]
    public function transaction_history_matches_balance_after_multiple_operations(): void
    {
        $user = User::factory()->create();

        $this->wallet->charge($user, 100000);
        $this->wallet->purchase($user, 30000);
        $this->wallet->addCommission($user, 15000);

        $wallet = $this->wallet->getOrCreateWallet($user);

        $this->assertEquals(85000, (float) $wallet->balance);
        $this->assertCount(3, $wallet->transactions);
        $this->assertEquals(85000, (float) $wallet->transactions()->latest('id')->first()->balance_after);
    }
}
