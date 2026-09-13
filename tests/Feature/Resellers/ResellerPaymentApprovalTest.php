<?php

namespace Tests\Feature\Resellers;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @test */
    public function reseller_can_confirm_their_customers_personal_wallet_charge(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customer, $method, 500000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'user'
        );

        $confirmed = $this->payments->confirmManualByReseller($payment, $reseller);

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals($reseller->id, $confirmed->reviewed_by_reseller_id);
        $this->assertNull($confirmed->reviewed_by);
        // کیف‌پول شخصیِ مشتری شارژ شده، نه کیف‌پول نماینده
        $this->assertEquals(500000, $this->wallet->balance($customer));
        $this->assertEquals(0, $this->wallet->balance($reseller));
    }

    /** @test */
    public function a_reseller_cannot_confirm_another_resellers_customer_payment(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();
        $customerOfA = User::factory()->create(['reseller_id' => $resellerA->id]);
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customerOfA, $method, 500000, 'wallet_charge', reseller: $resellerA, walletOwnerType: 'user'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManualByReseller($payment, $resellerB);
    }

    /** @test */
    public function main_admin_cannot_confirm_a_reseller_scoped_customer_payment(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $method = PaymentMethod::factory()->create();
        $admin = Admin::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customer, $method, 500000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'user'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManual($payment, $admin);
    }

    /** @test */
    public function reseller_cannot_confirm_their_own_reseller_wallet_topup(): void
    {
        $reseller = Reseller::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $reseller->user, $method, 1000000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'reseller'
        );

        $this->expectException(ResellerScopeViolationException::class);
        $this->payments->confirmManualByReseller($payment, $reseller);
    }

    /** @test */
    public function main_admin_confirming_a_reseller_topup_credits_the_reseller_wallet_not_the_owners_personal_wallet(): void
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
        // کیف‌پول شخصیِ owner (به‌عنوان یک مشتری عادی) دست‌نخورده مانده
        $this->assertEquals(0, $this->wallet->balance($reseller->user));
    }

    /** @test */
    public function reseller_can_reject_their_customers_payment(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $customer, $method, 500000, 'wallet_charge', reseller: $reseller, walletOwnerType: 'user'
        );

        $rejected = $this->payments->rejectByReseller($payment, $reseller);

        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals(0, $this->wallet->balance($customer));
    }

    /** @test */
    public function ordinary_main_bot_wallet_charge_is_completely_unaffected(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();
        $admin = Admin::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');

        $this->assertNull($payment->reseller_id);
        $this->assertEquals('user', $payment->wallet_owner_type);

        $confirmed = $this->payments->confirmManual($payment, $admin);

        $this->assertEquals(200000, $this->wallet->balance($user));
        $this->assertEquals($admin->id, $confirmed->reviewed_by);
    }
}
