<?php

namespace Tests\Feature\Purchase;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Purchase\PriceSnapshot;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Purchase\RefundService;
use App\Services\Core\Purchase\ResellerDebtLimitException;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۵۹ بلوپرینت (Reseller Purchase Tests) به‌علاوه‌ی اسنپ‌شات قیمت
 * (بند ۱۲) و دروازه‌های خرید (بند ۱۵).
 */
class PurchaseFlowTest extends TestCase
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

        $this->fakeSuccessfulPanel();
    }

    protected function fakeSuccessfulPanel(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
            ) {
                $username = rawurldecode(
                    substr($url, strrpos($url, '/') + 1)
                );

                if ($username === 't') {
                    return Http::response([
                        'success' => true,
                        'obj' => [
                            'inboundIds' => [1],
                            'flow' => '',
                            'limitIp' => 0,
                        ],
                    ], 200);
                }

                return Http::response([
                    'success' => false,
                    'obj' => null,
                    'msg' => 'record not found',
                ], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0],
                'subscription_url' => 'https://sub.example.test/abc',
            ], 200);
        });
    }

    protected function makeProduct(float $mainPrice = 120000, ?float $resellerPrice = null): Product
    {
        $category = Category::factory()->create([
            'status' => 'active',
            'available_to_resellers' => true,
        ]);

        $panel = ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 't', 'sub_base_url' => 'https://s.test/sub'],
        ]);

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $mainPrice,
            'reseller_price' => $resellerPrice,
            'status' => 'active',
        ]);
    }

    /* ── اسنپ‌شات قیمت — بند ۱۲ ─────────────────────────────────── */

    #[Test]
    public function an_order_freezes_the_price_at_purchase_time(): void
    {
        $product = $this->makeProduct(mainPrice: 120000);
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 200000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'test:snapshot'
        );

        // قیمت محصول بعد از خرید عوض می‌شود
        $product->update(['main_price' => 999000]);

        $order = $account->order->fresh();

        $this->assertEquals(120000, (float) $order->main_price);
    }

    #[Test]
    public function price_snapshot_separates_reseller_price_from_customers_price_for_resellers(): void
    {
        $product = $this->makeProduct(mainPrice: 150000, resellerPrice: 100000);

        $snapshot = PriceSnapshot::forResellerStore($product, 130000);

        $this->assertEquals(100000, $snapshot->resellerPrice);
        $this->assertEquals(130000, $snapshot->customersPrice);
        $this->assertEquals(30000, $snapshot->resellerProfit());
    }

    /* ── Double-Debit — بند ۱۹ و ۲۱ ─────────────────────────────── */

    #[Test]
    public function a_reseller_purchase_debits_the_customer_and_the_reseller_in_one_operation(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeProduct(mainPrice: 150000, resellerPrice: 100000);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => 130000,
            'is_enabled' => true,
        ]);

        $user = User::factory()->create();
        $store = StoreContext::reseller($reseller);
        $customer = $this->identity->resolveCustomerAccount($user, $store);

        $this->wallet->credit($customer, 130000);
        $this->wallet->credit($reseller, 100000);

        $account = $this->purchase->purchase(
            $customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:double-debit'
        );

        // هر دو طرف دقیقاً به اندازه‌ی سهم خودشان کسر شده‌اند
        $this->assertEquals(0, $this->wallet->getBalance($customer));
        $this->assertEquals(0, $this->wallet->getBalance($reseller));

        $order = $account->order;
        $this->assertEquals(100000, (float) $order->reseller_price);
        $this->assertEquals(130000, (float) $order->customers_price);
    }

    /* ── سقف بدهی — بند ۲۰، سناریوهای دقیق بند ۵۹ ───────────────── */

    #[Test]
    public function a_reseller_at_minus_450_with_limit_500_can_still_buy_something_costing_50(): void
    {
        [$customer, $store, $product] = $this->resellerSetup(
            debtLimit: 500, resellerPrice: 50, customersPrice: 80
        );

        // اعتبار را به -۴۵۰ می‌رسانیم
        $this->wallet->adminAdjust($store->reseller, -450);
        $this->assertEquals(-450, $this->wallet->getBalance($store->reseller));

        $this->wallet->credit($customer, 80);

        $account = $this->purchase->purchase(
            $customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:debt-allowed'
        );

        // دقیقاً روی مرز -۵۰۰: مجاز
        $this->assertEquals(-500, $this->wallet->getBalance($store->reseller));
        $this->assertEquals('account_created', $account->order->fresh()->status);
    }

    #[Test]
    public function a_reseller_at_minus_450_with_limit_500_is_blocked_at_cost_51(): void
    {
        [$customer, $store, $product] = $this->resellerSetup(
            debtLimit: 500, resellerPrice: 51, customersPrice: 80
        );

        $this->wallet->adminAdjust($store->reseller, -450);
        $this->wallet->credit($customer, 80);

        try {
            $this->purchase->purchase(
                $customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:debt-blocked'
            );
            $this->fail('خرید فراتر از سقف بدهی باید مسدود می‌شد.');
        } catch (ResellerDebtLimitException) {
            // انتظار همین است
        }

        // بند ۲۰: در حالت مسدود هیچ‌کدام از سه چیز نباید رخ دهد
        $this->assertEquals(80, $this->wallet->getBalance($customer), 'مشتری نباید کسر می‌شد');
        $this->assertEquals(-450, $this->wallet->getBalance($store->reseller), 'نماینده نباید کسر می‌شد');
        $this->assertEquals(0, Order::count(), 'هیچ سفارشی نباید ساخته می‌شد');
    }

    #[Test]
    public function a_reseller_with_no_debt_limit_behaves_exactly_as_before(): void
    {
        [$customer, $store, $product] = $this->resellerSetup(
            debtLimit: 0, resellerPrice: 100, customersPrice: 130
        );

        $this->wallet->credit($customer, 130);
        $this->wallet->credit($store->reseller, 50); // کمتر از قیمت عمده

        $this->expectException(ResellerDebtLimitException::class);
        $this->purchase->purchase($customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:no-limit');
    }

    /* ── دروازه‌ها — بند ۱۵ ──────────────────────────────────────── */

    #[Test]
    public function a_customer_of_one_store_cannot_buy_from_another_store(): void
    {
        $resellerA = Reseller::factory()->create(['status' => 'active']);
        $resellerB = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeProduct();

        $user = User::factory()->create();
        $customerOfA = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($resellerA));
        $this->wallet->credit($customerOfA, 500000);

        // تلاش برای خرید از فروشگاه B با حساب مشتریِ A
        $this->expectException(PurchaseNotAllowedException::class);
        $this->purchase->purchase(
            $customerOfA, $product, StoreContext::reseller($resellerB), 'reseller_bot',
            idempotencyKey: 'test:cross-store'
        );
    }

    #[Test]
    public function an_inactive_store_cannot_sell(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'inactive']);
        $product = $this->makeProduct();
        $user = User::factory()->create();
        $store = StoreContext::reseller($reseller);
        $customer = $this->identity->resolveCustomerAccount($user, $store);
        $this->wallet->credit($customer, 500000);

        $this->expectException(PurchaseNotAllowedException::class);
        $this->purchase->purchase($customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:inactive');
    }

    #[Test]
    public function a_customer_without_enough_balance_is_rejected_before_anything_happens(): void
    {
        $product = $this->makeProduct(mainPrice: 120000);
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 1000);

        try {
            $this->purchase->purchase($customer, $product, StoreContext::main(), idempotencyKey: 'test:poor');
            $this->fail('خرید بدون موجودی کافی باید رد می‌شد.');
        } catch (InsufficientBalanceException) {
        }

        $this->assertEquals(1000, $this->wallet->getBalance($customer));
        $this->assertEquals(0, Order::count());
    }

    #[Test]
    public function a_product_that_reached_its_sale_limit_cannot_be_bought(): void
    {
        $product = $this->makeProduct(mainPrice: 1000);
        $product->update(['sale_limit' => 1]);

        $buyer = fn () => $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());

        $first = $buyer();
        $this->wallet->credit($first, 5000);
        $this->purchase->purchase($first, $product, StoreContext::main(), idempotencyKey: 'test:limit-1');

        $second = $buyer();
        $this->wallet->credit($second, 5000);

        $this->expectException(PurchaseNotAllowedException::class);
        $this->purchase->purchase($second, $product, StoreContext::main(), idempotencyKey: 'test:limit-2');
    }

    /* ── بازگشت وجه ──────────────────────────────────────────────── */

    #[Test]
    public function refunding_a_reseller_order_gives_both_sides_their_money_back(): void
    {
        [$customer, $store, $product] = $this->resellerSetup(
            debtLimit: 0, resellerPrice: 100, customersPrice: 130
        );

        $this->wallet->credit($customer, 130);
        $this->wallet->credit($store->reseller, 100);

        $account = $this->purchase->purchase(
            $customer, $product, $store, 'reseller_bot', idempotencyKey: 'test:refund'
        );

        app(RefundService::class)->refundOrder($account->order, 'تست بازگشت');

        $this->assertEquals(130, $this->wallet->getBalance($customer));
        $this->assertEquals(100, $this->wallet->getBalance($store->reseller));
        $this->assertEquals(Order::STATUS_REFUNDED, $account->order->fresh()->status);
    }

    #[Test]
    public function refund_uses_the_order_price_snapshot_after_product_prices_change(): void
    {
        [$customer, $store, $product] = $this->resellerSetup(
            debtLimit: 0, resellerPrice: 90000, customersPrice: 140000
        );

        $this->wallet->credit($customer, 140000);
        $this->wallet->credit($store->reseller, 90000);

        $account = $this->purchase->purchase(
            $customer,
            $product,
            $store,
            'reseller_bot',
            idempotencyKey: 'test:refund-snapshot'
        );

        $order = $account->order->fresh();

        $this->assertEquals(90000, (float) $order->reseller_price);
        $this->assertEquals(140000, (float) $order->customers_price);

        // قیمت‌های Product بعد از خرید تغییر می‌کنند.
        $product->update([
            'main_price' => 300000,
            'reseller_price' => 250000,
        ]);

        $product->resellerPrices()->update([
            'customers_price' => 280000,
        ]);

        app(RefundService::class)->refundOrder($order, 'تست بازگشت بر اساس Snapshot');

        // Refund باید از Snapshot سفارش استفاده کند، نه قیمت‌های جدید Product.
        $this->assertEquals(140000, $this->wallet->getBalance($customer));
        $this->assertEquals(90000, $this->wallet->getBalance($store->reseller));

        $this->assertEquals(
            Order::STATUS_REFUNDED,
            $order->fresh()->status
        );
    }

    #[Test]
    public function an_order_cannot_be_refunded_twice(): void
    {
        $product = $this->makeProduct(mainPrice: 5000);
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 5000);

        $account = $this->purchase->purchase(
            $customer, $product, StoreContext::main(), idempotencyKey: 'test:refund-twice'
        );

        $refunds = app(RefundService::class);
        $refunds->refundOrder($account->order);

        $balanceAfterFirst = $this->wallet->getBalance($customer);

        try {
            $refunds->refundOrder($account->order->fresh());
        } catch (\Throwable) {
            // بازگشت دوم یا رد می‌شود یا بی‌اثر است — هر دو قابل قبول‌اند
        }

        $this->assertEquals($balanceAfterFirst, $this->wallet->getBalance($customer));
    }

    /**
     * @return array{0: \App\Models\CustomerAccount, 1: StoreContext, 2: Product}
     */
    protected function resellerSetup(float $debtLimit, float $resellerPrice, float $customersPrice): array
    {
        $reseller = Reseller::factory()->create([
            'status' => 'active',
            'debt_limit' => $debtLimit,
        ]);

        $product = $this->makeProduct(mainPrice: $customersPrice * 2, resellerPrice: $resellerPrice);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        $store = StoreContext::reseller($reseller);
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), $store);

        return [$customer, $store, $product];
    }
}
