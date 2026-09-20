<?php

namespace Tests\Feature\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;


/**
 * طبق تصمیم صریح: «شارژ حساب» (کیف‌پول شخصی مشتری در ربات نماینده) را
 * خودِ نماینده تایید می‌کند چون پول را مستقیم می‌گیرد؛ «شارژ حساب
 * نماینده» (اعتبار خودِ نماینده نزد پلتفرم) هم‌چنان با ادمین اصلی است.
 * این تست‌ها دقیقاً همین دو مسیرِ جدا و مرزهای Authorization بینشان را
 * پوشش می‌دهند.
 */
class ResellerPaymentApprovalTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected PaymentService $payments;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        $this->payments = app(PaymentService::class);
        $this->wallet = app(WalletService::class);
    }

    #[Test]
    public function reseller_can_confirm_their_customers_personal_wallet_charge(): void
    {
        $reseller = Reseller::factory()->create();
        $customerUser = User::factory()->create();

        $customer = app(IdentityService::class)
            ->resolveCustomerAccount(
                $customerUser,
                StoreContext::fromReseller($reseller),
            );
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customerUser,
            $method,
            500000,
            'wallet_charge',
            reseller: $reseller,
            walletOwnerType: 'user'
        );

        $confirmed = $this->payments->confirmManualByReseller($payment, $reseller);

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals($reseller->id, $confirmed->reviewed_by_reseller_id);
        $this->assertNull($confirmed->reviewed_by);
        // کیف‌پول شخصیِ مشتری شارژ شده، نه کیف‌پول نماینده
        $this->assertEquals(500000, $this->wallet->balance($customer));
        $this->assertEquals(0, $this->wallet->balance($reseller));
    }

    #[Test]
    public function a_reseller_cannot_confirm_another_resellers_customer_payment(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        $customerOfA = User::factory()->create(['reseller_id' => $resellerA->id]);
        $method = PaymentMethod::factory()->create();

       ['payment' => $payment] = $this->payments->initiate(
            $customerOfA,
            $method,
            500000,
            'wallet_charge',
            reseller: $resellerA,
            walletOwnerType: 'user'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManualByReseller($payment, $resellerB);
    }

    #[Test]
    public function main_admin_cannot_confirm_a_reseller_scoped_customer_payment(): void
    {
        $reseller = Reseller::factory()->create();
        $customerUser = User::factory()->create();

        $customer = app(IdentityService::class)
            ->resolveCustomerAccount(
                $customerUser,
                StoreContext::fromReseller($reseller),
            );
        $method = PaymentMethod::factory()->create();
        $admin = Admin::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customerUser,
            $method,
            500000,
            'wallet_charge',
            reseller: $reseller,
            walletOwnerType: 'user'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManual($payment, $admin);
    }

    #[Test]
    public function reseller_cannot_confirm_their_own_main_wallet_topup(): void
    {
        $reseller = Reseller::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $reseller->user, $method, 1000000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'reseller'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManualByReseller($payment, $reseller);
    }

    #[Test]
    public function main_admin_confirming_a_reseller_topup_credits_the_owners_main_wallet(): void
    {
        $reseller = Reseller::factory()->create();
        $method = PaymentMethod::factory()->create();
        $admin = Admin::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $reseller->user, $method, 1000000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'reseller'
        );

        $confirmed = $this->payments->confirmManual($payment, $admin);

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals($admin->id, $confirmed->reviewed_by);
        $this->assertNull($confirmed->reviewed_by_reseller_id);
        $this->assertEquals(1000000, $this->wallet->balance($reseller));
        // Rule 6 سند: Wallet صاحبِ نماینده برای reseller_price همان Wallet
        // خودِ او در Main است؛ «اعتبار نماینده» Wallet جدا ندارد.
        $ownerMain = app(IdentityService::class)
            ->resolveCustomerAccount(
                $reseller->user,
                StoreContext::main(),
            );

        $this->assertEquals(1000000, $this->wallet->balance($ownerMain));
        $this->assertEquals(
            $this->wallet->walletFor($ownerMain)->id,
            $this->wallet->getOrCreateWallet($reseller)->id,
        );
    }

    #[Test]
    public function reseller_can_reject_their_customers_payment(): void
    {
        $reseller = Reseller::factory()->create();
        $customerUser = User::factory()->create();

        $customer = app(IdentityService::class)
            ->resolveCustomerAccount(
                $customerUser,
                StoreContext::fromReseller($reseller),
            );
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customerUser,
            $method,
            500000,
            'wallet_charge',
            reseller: $reseller,
            walletOwnerType: 'user'
        );

        $rejected = $this->payments->rejectByReseller($payment, $reseller);

        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals(0, $this->wallet->balance($customer));
    }

    #[Test]
    public function ordinary_main_bot_wallet_charge_is_completely_unaffected(): void
    {
       $user = User::factory()->create();

        $customer = app(IdentityService::class)
            ->resolveCustomerAccount(
                $user,
                StoreContext::main(),
            );

        $method = PaymentMethod::factory()->create();
        $admin = Admin::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');

        $this->assertNull($payment->reseller_id);
        $this->assertEquals('user', $payment->wallet_owner_type);

        $confirmed = $this->payments->confirmManual($payment, $admin);

        $this->assertEquals(200000, $this->wallet->balance($customer));
        $this->assertEquals($admin->id, $confirmed->reviewed_by);
    }
}
