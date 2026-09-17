<?php

namespace Tests\Feature\Purchase;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۶۰ (Provisioning Tests) و بند ۶۱ (Renewal Tests) بلوپرینت.
 */
class ProvisioningAndRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseService $purchase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchase = app(PurchaseService::class);
        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);
    }

    protected function panelSucceeds(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0, 'subId' => 'sub123'],
        ], 200)]);
    }

    protected function panelFails(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'msg' => 'panel down'], 500)]);
    }

    protected function makeProduct(float $price = 100000, int $days = 30, float $gb = 50): Product
    {
        $category = Category::factory()->create(['status' => 'active']);

        $panel = ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 't', 'sub_base_url' => 'https://s.test/sub'],
        ]);

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'price' => $price,
            'duration_days' => $days,
            'traffic_gb' => $gb,
            'status' => 'active',
        ]);
    }

    protected function buyer(float $balance = 500000)
    {
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($customer, $balance);

        return $customer;
    }

    /* ── Provisioning — بند ۶۰ ──────────────────────────────────── */

    #[Test]
    public function a_successful_first_attempt_creates_the_account_and_closes_the_order(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct();

        $account = $this->purchase->purchase(
            $this->buyer(), $product, StoreContext::main(), idempotencyKey: 'prov:ok'
        );

        $this->assertEquals('active', $account->status);
        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $account->order->fresh()->status);
        $this->assertEquals(1, $account->order->fresh()->provision_attempts);
    }

    /**
     * مهم‌ترین تست این فاز. وقتی پنل شکست می‌خورد، پول از قبل کسر شده —
     * و این حالت باید صریحاً از «شکست مالی» قابل تشخیص باشد، وگرنه
     * ادمین از روی دیتابیس نمی‌فهمد باید پول را برگرداند یا نه.
     */
    #[Test]
    public function a_panel_failure_after_payment_leaves_an_unambiguous_financial_state(): void
    {
        $this->panelFails();
        $product = $this->makeProduct(price: 100000);
        $customer = $this->buyer(balance: 100000);

        try {
            $this->purchase->purchase($customer, $product, StoreContext::main(), idempotencyKey: 'prov:fail');
            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();

        // پول کسر شده و این واقعیت در دیتابیس صریح است
        $this->assertEquals(0, $this->wallet->getBalance($customer));
        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->status);
        $this->assertTrue($order->isFinanciallySettled());
        $this->assertTrue($order->needsAttention());
        $this->assertNotEmpty($order->failure_reason);
        $this->assertEquals(0, Account::count());
    }

    #[Test]
    public function a_failed_order_can_be_retried_up_to_three_times(): void
    {
        $this->panelFails();
        $product = $this->makeProduct();

        try {
            $this->purchase->purchase($this->buyer(), $product, StoreContext::main(), idempotencyKey: 'prov:retry');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();
        $provisioning = app(ProvisioningService::class);

        $this->assertTrue($provisioning->canRetry($order));

        // تلاش دوم و سوم هم شکست می‌خورند
        foreach ([2, 3] as $expectedAttempt) {
            try {
                $provisioning->provision($order->fresh());
            } catch (ProvisioningFailedException) {
            }

            $this->assertEquals($expectedAttempt, $order->fresh()->provision_attempts);
        }

        // بعد از سه تلاش دیگر retry خودکار مجاز نیست — نیازمند ادمین
        $this->assertFalse($provisioning->canRetry($order->fresh()));
    }

    #[Test]
    public function a_retry_that_succeeds_recovers_the_order_without_charging_again(): void
    {
        Http::fakeSequence()
            ->push([
                'success' => false,
                'msg' => 'panel down',
            ], 500)
            ->push([
                'success' => true,
                'obj' => [
                    'inboundIds' => [1],
                    'flow' => '',
                    'limitIp' => 0,
                    'subId' => 'sub123',
                ],
            ], 200)
            ->push([
                'success' => true,
                'obj' => [
                    'success' => true,
                ],
            ], 200);

        $product = $this->makeProduct(price: 100000);
        $customer = $this->buyer(balance: 100000);

        try {
            $this->purchase->purchase(
                $customer,
                $product,
                StoreContext::main(),
                idempotencyKey: 'prov:recover'
            );
        } catch (ProvisioningFailedException) {
        }

        $balanceAfterFailure = $this->wallet->getBalance($customer);

        $account = $this->purchase->retryProvisioning(Order::firstOrFail());

        $this->assertEquals('active', $account->status);
        $this->assertEquals(
            Order::STATUS_ACCOUNT_CREATED,
            $account->order->fresh()->status
        );

        // هیچ کسر دوباره‌ای نباید رخ داده باشد
        $this->assertEquals(
            $balanceAfterFailure,
            $this->wallet->getBalance($customer)
        );
    }

    #[Test]
    public function a_duplicate_purchase_request_never_creates_two_accounts(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct(price: 50000);
        $customer = $this->buyer(balance: 200000);

        $key = 'prov:duplicate-click';

        $first = $this->purchase->purchase($customer, $product, StoreContext::main(), idempotencyKey: $key);
        $second = $this->purchase->purchase($customer, $product, StoreContext::main(), idempotencyKey: $key);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Account::count());
        $this->assertEquals(1, Order::count());
        // فقط یک بار کسر شده
        $this->assertEquals(150000, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function provisioning_refuses_to_run_on_an_unpaid_order(): void
    {
        $product = $this->makeProduct();
        $customer = $this->buyer();

        $order = Order::create([
            'user_id' => $customer->user_id,
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'sales_channel' => 'main_bot',
            'base_price' => 1000,
            'core_price' => 1000,
            'sold_price' => 1000,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->expectException(ProvisioningFailedException::class);
        app(ProvisioningService::class)->provision($order);
    }

    /* ── Renewal — بند ۶۱ ───────────────────────────────────────── */

    #[Test]
    public function a_renewal_extends_time_and_resets_traffic(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct(price: 100000, days: 30, gb: 50);
        $customer = $this->buyer(balance: 300000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'renew:setup'
        );

        // شبیه‌سازی مصرف حجم
        $account->update(['traffic_used_gb' => 48]);
        $originalExpiry = $account->expires_at->copy();

        $renewed = app(RenewalService::class)->renew($account->fresh(), 'renew:once');

        // بند ۲۸: هر دو باید reset/extend شوند، نه فقط تاریخ
        $this->assertTrue($renewed->expires_at->greaterThan($originalExpiry), 'تاریخ باید تمدید می‌شد');
        $this->assertEquals(0, (float) $renewed->traffic_used_gb, 'حجم مصرفی باید صفر می‌شد');
        $this->assertEquals(50, (float) $renewed->traffic_gb);
    }

    #[Test]
    public function a_renewal_charges_the_customer_wallet(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct(price: 100000);
        $customer = $this->buyer(balance: 300000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'renew:pay-setup'
        );

        $this->assertEquals(200000, $this->wallet->getBalance($customer));

        app(RenewalService::class)->renew($account->fresh(), 'renew:pay');

        $this->assertEquals(100000, $this->wallet->getBalance($customer));
    }

    /**
     * تمدید زودهنگام نباید روزهای باقی‌مانده را بسوزاند — مبنا باید
     * انقضای فعلی باشد، نه امروز.
     */
    #[Test]
    public function renewing_early_adds_to_the_remaining_time_instead_of_discarding_it(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct(price: 10000, days: 30);
        $customer = $this->buyer(balance: 100000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'renew:early-setup'
        );

        $remaining = $account->expires_at->copy();

        $renewed = app(RenewalService::class)->renew($account->fresh(), 'renew:early');

        // باید حدود ۶۰ روز از حالا باشد، نه ۳۰
        $this->assertEqualsWithDelta(
            $remaining->addDays(30)->timestamp,
            $renewed->expires_at->timestamp,
            60
        );
    }

    #[Test]
    public function a_panel_failure_during_renewal_is_recorded_not_silently_swallowed(): void
    {
        Http::fakeSequence()
        // خرید اولیه: resolve template
        ->push([
            'success' => true,
            'obj' => [
                'inboundIds' => [1],
                'flow' => '',
                'limitIp' => 0,
                'subId' => 'sub123',
            ],
        ], 200)

        // خرید اولیه: create account
        ->push([
            'success' => true,
            'obj' => [
                'success' => true,
            ],
        ], 200)

        // تمدید: get current account
        ->push([
            'success' => true,
            'obj' => [
                'id' => 1,
                'email' => 'test@example.com',
                'enable' => true,
                'totalGB' => 50,
                'expiryTime' => now()->addDays(30)->timestamp * 1000,
                'subId' => 'sub123',
                'flow' => '',
            ],
        ], 200)

        // تمدید: update account باید شکست بخورد
        ->push([
            'success' => false,
            'msg' => 'panel down',
        ], 500);
        $product = $this->makeProduct(price: 10000);
        $customer = $this->buyer(balance: 100000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'renew:fail-setup'
        );

        $originalExpiry = $account->expires_at->copy();



        try {
            app(RenewalService::class)->renew($account->fresh(), 'renew:fail');
            $this->fail('شکست پنل در تمدید باید استثنا می‌داد.');
        } catch (\Throwable) {
        }

        // اکانت نباید ظاهراً تمدید شده باشد
        $this->assertEquals($originalExpiry->timestamp, $account->fresh()->expires_at->timestamp);

        // ولی سفارش تمدید باید صریحاً در حالت «پول گرفته شده، تحویل نشده» باشد
        $renewalOrder = Order::orderByDesc('id')->first();
        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $renewalOrder->status);
        $this->assertNotEmpty($renewalOrder->failure_reason);
    }

    #[Test]
    public function a_duplicate_renewal_request_only_charges_once(): void
    {
        $this->panelSucceeds();
        $product = $this->makeProduct(price: 25000);
        $customer = $this->buyer(balance: 200000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'renew:dup-setup'
        );

        $balanceBefore = $this->wallet->getBalance($customer);
        $renewals = app(RenewalService::class);

        $renewals->renew($account->fresh(), 'renew:dup');
        $renewals->renew($account->fresh(), 'renew:dup');

        $this->assertEquals($balanceBefore - 25000, $this->wallet->getBalance($customer));
    }
}
