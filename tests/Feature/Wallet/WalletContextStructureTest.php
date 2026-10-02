<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\InsufficientBalanceException;
use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز ۱۳ — Wallet = User + StoreContext (سند بند ۲۱ تا ۲۸، Rule 5/6/7).
 */
class WalletContextStructureTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);
    }

    #[Test]
    public function a_user_has_exactly_one_wallet_per_context(): void
    {
        $user = User::factory()->create();
        $resellerA = Reseller::factory()->create();

        $main1 = $this->wallet->walletForContext($user, StoreContext::main());
        $main2 = $this->wallet->walletForContext($user, StoreContext::main());
        $inA1 = $this->wallet->walletForContext($user, StoreContext::reseller($resellerA));
        $inA2 = $this->wallet->walletForContext($user, StoreContext::reseller($resellerA));

        $this->assertSame($main1->id, $main2->id);
        $this->assertSame($inA1->id, $inA2->id);
        $this->assertNotSame($main1->id, $inA1->id);
        $this->assertEquals(2, Wallet::where('user_id', $user->id)->count());

        $this->assertEquals('main', $main1->store_type);
        $this->assertNull($main1->reseller_id);
        $this->assertEquals('main', $main1->scope_key);
        $this->assertEquals('reseller:'.$resellerA->id, $inA1->scope_key);
    }

    #[Test]
    public function wallet_balances_of_different_contexts_are_independent(): void
    {
        $user = User::factory()->create();
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        $c = Reseller::factory()->create();

        $main = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $inA = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($a));
        $inB = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($b));
        $inC = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($c));

        $this->wallet->credit($main, 500);
        $this->wallet->credit($inA, 200);
        $this->wallet->credit($inB, 750);
        $this->wallet->credit($inC, 100);

        $this->wallet->debit($inA, 150);

        $this->assertEquals(500, $this->wallet->balance($main));
        $this->assertEquals(50, $this->wallet->balance($inA));
        $this->assertEquals(750, $this->wallet->balance($inB));
        $this->assertEquals(100, $this->wallet->balance($inC));
    }

    #[Test]
    public function the_reseller_owners_supply_wallet_is_their_main_wallet(): void
    {
        $reseller = Reseller::factory()->create();
        $ownerMain = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::main());

        $this->wallet->credit($reseller, 1000);

        $this->assertEquals(1000, $this->wallet->balance($ownerMain));
        $this->assertSame(
            $this->wallet->walletFor($ownerMain)->id,
            $this->wallet->getOrCreateWallet($reseller)->id,
        );

        // یک Wallet در Main برای این User وجود دارد، نه دو تا (نه «اعتبار نماینده»ی جدا)
        $this->assertEquals(1, Wallet::where('user_id', $reseller->user_id)->where('scope_key', 'main')->count());
        $this->assertEquals(1000, (int) $reseller->wallet->balance);
    }

    #[Test]
    public function the_owners_wallet_in_the_resellers_own_store_stays_separate_from_main(): void
    {
        $reseller = Reseller::factory()->create();

        $ownerMain = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::main());
        $ownerInOwnStore = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::reseller($reseller));

        $this->wallet->credit($reseller, 1000);
        $this->wallet->credit($ownerInOwnStore, 40);

        $this->assertEquals(1000, $this->wallet->balance($ownerMain));
        $this->assertEquals(40, $this->wallet->balance($ownerInOwnStore));
    }

    #[Test]
    public function the_debt_floor_belongs_to_the_operation_not_to_the_shared_wallet(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 500]);
        $ownerMain = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::main());

        // تأمین reseller_price می‌تواند تا -debt_limit پیش برود
        $this->wallet->debit($reseller, 300);
        $this->assertEquals(-300, $this->wallet->balance($ownerMain));

        // همان Wallet برای خرید شخصیِ مالک در Main کفِ صفر دارد
        $this->expectException(InsufficientBalanceException::class);
        $this->wallet->debit($ownerMain, 1);
    }

    #[Test]
    public function the_reseller_debt_floor_still_blocks_beyond_the_limit(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 500]);

        $this->wallet->debit($reseller, 300);

        $this->expectException(InsufficientBalanceException::class);
        $this->wallet->debit($reseller, 300); // -600 < -500
    }

    #[Test]
    public function the_database_blocks_a_second_main_wallet_even_though_reseller_id_is_null(): void
    {
        $user = User::factory()->create();

        Wallet::create(['user_id' => $user->id, 'store_type' => 'main', 'balance' => 0]);

        $this->expectException(QueryException::class);
        Wallet::create(['user_id' => $user->id, 'store_type' => 'main', 'balance' => 0]);
    }

    #[Test]
    public function a_reseller_context_wallet_requires_a_reseller_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        Wallet::create(['user_id' => $user->id, 'store_type' => 'reseller', 'balance' => 0]);
    }

    #[Test]
    public function a_bare_user_owner_always_means_the_main_wallet(): void
    {
        // Rule 12: Context هرگز از User حدس زده نمی‌شود (ستون users.reseller_id در Baseline حذف شده)
        $reseller = Reseller::factory()->create();
        $user = User::factory()->create();

        $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        $this->wallet->credit($user, 10);

        $this->assertEquals(10, $this->wallet->balanceIn($user, StoreContext::main()));
        $this->assertEquals(0, $this->wallet->balanceIn($user, StoreContext::reseller($reseller)));
    }

    #[Test]
    public function a_guest_membership_has_no_wallet(): void
    {
        $guest = CustomerAccount::create(['user_id' => null, 'store_type' => 'main', 'status' => 'active']);

        $this->expectException(\InvalidArgumentException::class);
        $this->wallet->getOrCreateWallet($guest);
    }
}
