<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use App\Services\Core\Payments\InvalidPaymentTransitionException;
use App\Services\Core\Payments\PaymentGatewayFactory;
use App\Services\Core\Payments\PaymentStateMachine;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;
use App\Models\CustomerAccount;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentService $payments;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                return new Message([
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => [
                        'id' => $params['chat_id'] ?? 111222333,
                        'type' => 'private',
                    ],
                    'text' => $params['text'] ?? '',
                ]);
            });

        $this->app->instance(Api::class, $telegram);

        $this->payments = app(PaymentService::class);
        $this->wallet = app(WalletService::class);

        // در پروژه‌ی اصلی این مقدار باید در config/services.php واقعی تنظیم
        // شود؛ اینجا فقط برای تست است.
        config()->set('services.zarinpal.callback_url', 'https://melorin.test/payments/zarinpal/callback');
    }

    protected function mainWalletOwner(User $user): CustomerAccount
    {
        return app(IdentityService::class)
            ->resolveCustomerAccount($user, StoreContext::main());
    }

    #[Test]
    public function card_to_card_initiate_returns_bank_instructions_without_charging_wallet(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create(); // card_to_card به‌صورت پیش‌فرض

        ['payment' => $payment, 'initiation' => $result] = $this->payments->initiate(
            $user, $method, 200000, 'wallet_charge'
        );

        $this->assertEquals('pending', $payment->status);
        $this->assertNotEmpty($result->instructions['card_number']);
        $this->assertEquals(0.0, $this->wallet->balance($this->mainWalletOwner($user)));
    }

    #[Test]
    public function admin_confirming_a_manual_payment_charges_the_wallet(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');

        $confirmed = $this->payments->confirmManual($payment, $admin);

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals($admin->id, $confirmed->reviewed_by);
        $this->assertEquals(200000, $this->wallet->balance($this->mainWalletOwner($user)));
    }

    #[Test]
    public function admin_rejecting_a_manual_payment_never_touches_the_wallet(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');

        $rejected = $this->payments->reject($payment, $admin, 'رسید جعلی بود');

        $this->assertEquals('rejected', $rejected->status);
        $this->assertEquals(0.0, $this->wallet->balance($this->mainWalletOwner($user)));
    }

    #[Test]
    public function zarinpal_initiate_returns_a_redirect_url_and_stores_the_authority(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'A00000000000000000000000000123456'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        ['payment' => $payment, 'initiation' => $result] = $this->payments->initiate(
            $user, $method, 150000, 'wallet_charge'
        );

        $this->assertTrue($result->success);
        $this->assertStringContainsString('StartPay', $result->redirectUrl);
        $this->assertEquals('A00000000000000000000000000123456', $payment->fresh()->gateway_reference);
    }

    #[Test]
    public function zarinpal_successful_callback_confirms_payment_and_charges_wallet(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
            '*/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 998877],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 150000, 'wallet_charge');

        $confirmed = $this->payments->handleCallback($payment, ['Authority' => 'AUTH123', 'Status' => 'OK']);

        $this->assertEquals('confirmed', $confirmed->status);
        $this->assertEquals(150000, $this->wallet->balance($this->mainWalletOwner($user)));
    }

    #[Test]
    public function zarinpal_callback_does_not_trust_amount_from_query_string(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
            '*/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 998877],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        ['payment' => $payment] = $this->payments->initiate(
            $user, $method, 150000, 'wallet_charge'
        );

        $this->payments->handleCallback($payment, [
            'Authority' => 'AUTH123',
            'Status' => 'OK',
            'amount' => '1',
        ]);

        $this->assertEquals(150000, $this->wallet->balance($this->mainWalletOwner($user)));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'verify.json')
                && ($request->data()['amount'] ?? null) === 1500000;
        });
    }

    #[Test]
    public function zarinpal_cancelled_callback_rejects_payment_without_calling_verify(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 150000, 'wallet_charge');

        $result = $this->payments->handleCallback($payment, ['Authority' => 'AUTH123', 'Status' => 'NOK']);

        $this->assertEquals('rejected', $result->status);
        $this->assertEquals(0.0, $this->wallet->balance($this->mainWalletOwner($user)));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'verify.json'));
    }

    #[Test]
    public function refunding_a_confirmed_wallet_charge_deducts_the_balance_back(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');
        $this->payments->confirmManual($payment, $admin);

        $refunded = $this->payments->refund($payment, $admin);

        $this->assertEquals('refunded', $refunded->status);
        $this->assertEquals(0.0, $this->wallet->balance($this->mainWalletOwner($user)));
    }

    // ── فاز A۱ سند v2.1 (بند ۵۳): PaymentStateMachine به‌عنوان تنها مرجع ──

    #[Test]
    public function confirming_an_already_confirmed_payment_is_rejected_and_never_double_charges(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');
        $this->payments->confirmManual($payment, $admin);

        $this->expectException(InvalidPaymentTransitionException::class);

        try {
            $this->payments->confirmManual($payment->fresh(), $admin);
        } finally {
            $this->assertEquals(200000, $this->wallet->balance($this->mainWalletOwner($user)));
        }
    }

    #[Test]
    public function rejecting_an_already_confirmed_payment_is_blocked_and_the_wallet_charge_stands(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');
        $this->payments->confirmManual($payment, $admin);

        // شبیه‌سازی Race: نمونه‌ی درون‌حافظه‌ای هنوز pending می‌بیند (چون قبل
        // از confirm گرفته شده)، ولی reject() ردیف را دوباره از DB با قفل
        // می‌خواند، پس واقعیتِ «confirmed» را می‌بیند نه این کپیِ کهنه را.
        $this->expectException(InvalidPaymentTransitionException::class);

        try {
            $this->payments->reject($payment, $admin);
        } finally {
            $this->assertEquals('confirmed', $payment->fresh()->status);
            $this->assertEquals(200000, $this->wallet->balance($this->mainWalletOwner($user)));
        }
    }

    #[Test]
    public function confirming_a_rejected_payment_is_blocked(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');
        $this->payments->reject($payment, $admin, 'رسید جعلی بود');

        $this->expectException(InvalidPaymentTransitionException::class);

        try {
            $this->payments->confirmManual($payment->fresh(), $admin);
        } finally {
            $this->assertEquals(0.0, $this->wallet->balance($this->mainWalletOwner($user)));
        }
    }

    #[Test]
    public function refunding_the_same_payment_twice_is_blocked_and_only_debits_once(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 200000, 'wallet_charge');
        $this->payments->confirmManual($payment, $admin);
        $this->payments->refund($payment->fresh(), $admin);

        // برای این‌که تست معنا داشته باشد، اول دوباره شارژ می‌کنیم تا اگر
        // به‌اشتباه دوباره کسر شد، منفی‌شدن موجودی/خطا آن را لو بدهد.
        $this->wallet->credit($this->mainWalletOwner($user), 200000);

        $this->expectException(InvalidPaymentTransitionException::class);

        try {
            $this->payments->refund($payment->fresh(), $admin);
        } finally {
            $this->assertEquals(200000, $this->wallet->balance($this->mainWalletOwner($user)));
        }
    }

    #[Test]
    public function a_duplicate_zarinpal_webhook_confirms_only_once_and_never_calls_verify_twice(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
            '*/payment/verify.json' => Http::response([
                'data' => ['code' => 100, 'ref_id' => 998877],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        ['payment' => $payment] = $this->payments->initiate($user, $method, 150000, 'wallet_charge');

        $first = $this->payments->handleCallback($payment, ['Authority' => 'AUTH123', 'Status' => 'OK']);
        $second = $this->payments->handleCallback($first, ['Authority' => 'AUTH123', 'Status' => 'OK']);

        $this->assertEquals('confirmed', $second->status);
        $this->assertEquals(150000, $this->wallet->balance($this->mainWalletOwner($user)));
        Http::assertSentCount(2); // ۱ request.json (initiate) + ۱ verify.json (فقط تلاش اول)
    }
}
