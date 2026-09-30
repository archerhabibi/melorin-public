<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز W5 (نفر ۳) — بند ۶۲ و ۶۶ زیرسند: Reseller Website E2E + تست‌های
 * Reseller Scope / Customer Scope / Product Scope / Pricing / Wallet
 * Scope / Order Scope.
 *
 * تا پیش از این فاز، هیچ‌کدام از تست‌های W2 (`CheckoutFlowTest`،
 * `WalletChargeFlowTest`) Context نماینده را نمی‌سنجید؛ کنترلرها و
 * Facadeهای مشترک از ابتدا Context-aware نوشته شده بودند ولی روی آن
 * ادعایی «تست‌شده» وجود نداشت. این فایل همان شکاف را می‌بندد — و طبق
 * بند ۶۲ («هم‌زمان Financial Flow مربوط به Owner نیز باید Verification
 * شود»)، Debit دومِ کیف‌پول Main صاحبِ نماینده را هم می‌سنجد، نه فقط
 * کیف‌پول مشتری را.
 */
class ResellerCommerceFlowTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected IdentityService $identity;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
        $this->wallet = app(WalletService::class);

        $this->fakeSanaeiPanel();
    }

    /** محصولِ Core با یک پنل فعال؛ قیمت‌های main/reseller مشخص. */
    protected function makeProduct(float $mainPrice = 150000, float $resellerPrice = 100000): Product
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
            'main_price' => $mainPrice,
            'reseller_price' => $resellerPrice,
            'status' => 'active',
        ]);
    }

    /** یک نماینده‌ی فعال که این محصول را با customers_price مشخص فعال کرده است. */
    protected function resellerSelling(Product $product, float $customersPrice = 130000, array $resellerAttributes = []): Reseller
    {
        $reseller = Reseller::factory()->create($resellerAttributes + ['status' => 'active']);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        return $reseller;
    }

    #[Test]
    public function the_reseller_catalog_shows_customers_price_and_hides_products_it_has_not_enabled(): void
    {
        $sold = $this->makeProduct(mainPrice: 150000, resellerPrice: 100000);
        $sold->update(['name' => 'محصول-فعال-برای-نماینده']);
        $hidden = $this->makeProduct(mainPrice: 111111, resellerPrice: 90000);
        $hidden->update(['name' => 'محصول-فعال-نشده']);

        $reseller = $this->resellerSelling($sold, customersPrice: 130000);

        $response = $this->get(route('website.store.home', $reseller->slug))->assertOk();

        $response->assertSee('محصول-فعال-برای-نماینده');
        $response->assertSee(number_format(130000));            // customers_price — بند ۲۱ سند مادر
        $response->assertDontSee(number_format(150000));         // main_price نباید در فروشگاه نماینده دیده شود
        $response->assertDontSee(number_format(100000));         // reseller_price هرگز قیمتِ فروش به مشتری نیست
        $response->assertDontSee('محصول-فعال-نشده');
    }

    #[Test]
    public function a_reseller_customer_checkout_debits_both_wallets_and_creates_a_reseller_scoped_order(): void
    {
        $product = $this->makeProduct(mainPrice: 150000, resellerPrice: 100000);
        $reseller = $this->resellerSelling($product, customersPrice: 130000);

        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->wallet->credit($customer, 200000);
        $this->wallet->credit($reseller, 500000); // Wallet صاحبِ نماینده در Main (Rule 6)

        $response = $this->actingAs($user)->post(
            route('website.store.checkout.store', [$reseller->slug, $product->id]),
            ['idempotency_token' => 'reseller-checkout-1'],
        );

        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();

        $response->assertRedirect(route('website.store.orders.show', [$reseller->slug, $order->id]));

        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);
        $this->assertEquals($reseller->id, $order->reseller_id);

        // Price Snapshot (بند ۵۰ سند مادر)
        $this->assertNull($order->main_price);
        $this->assertEquals(100000, (float) $order->reseller_price);
        $this->assertEquals(130000, (float) $order->customers_price);

        // Debit اول: کیف‌پول مشتری در Context نماینده = customers_price
        $this->assertEquals(70000, $this->wallet->getBalance($customer));

        // Debit دوم (بند ۶۲): کیف‌پول Main صاحبِ نماینده = reseller_price
        $this->assertEquals(400000, $this->wallet->balance($reseller));
    }

    #[Test]
    public function a_reseller_store_purchase_never_touches_the_customers_main_wallet(): void
    {
        $product = $this->makeProduct();
        $reseller = $this->resellerSelling($product);

        $user = User::factory()->create();
        $mainAccount = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $resellerAccount = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        $this->wallet->credit($mainAccount, 999000);
        $this->wallet->credit($resellerAccount, 200000);
        $this->wallet->credit($reseller, 500000);

        $this->actingAs($user)->post(
            route('website.store.checkout.store', [$reseller->slug, $product->id]),
            ['idempotency_token' => 'wallet-isolation-1'],
        );

        $this->assertEquals(999000, $this->wallet->getBalance($mainAccount), 'کیف‌پول Main مشتری نباید دست بخورد (Wallet Independence)');
        $this->assertEquals(70000, $this->wallet->getBalance($resellerAccount));
    }

    #[Test]
    public function an_order_made_in_a_reseller_store_is_not_visible_from_the_main_store(): void
    {
        $product = $this->makeProduct();
        $reseller = $this->resellerSelling($product);

        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->wallet->credit($customer, 200000);
        $this->wallet->credit($reseller, 500000);

        $this->actingAs($user)->post(
            route('website.store.checkout.store', [$reseller->slug, $product->id]),
            ['idempotency_token' => 'order-scope-1'],
        );
        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();

        // خودِ همان کاربر، همان سفارش، ولی از Context اشتباه (Main) → ۴۰۴
        $this->actingAs($user)->get(route('website.orders.show', $order->id))->assertNotFound();
    }

    #[Test]
    public function a_customer_of_one_reseller_cannot_view_an_order_from_another_reseller_store(): void
    {
        $product = $this->makeProduct();
        $resellerA = $this->resellerSelling($product);
        $resellerB = $this->resellerSelling($product);

        $user = User::factory()->create();
        $customerA = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($resellerA));
        $this->wallet->credit($customerA, 200000);
        $this->wallet->credit($resellerA, 500000);

        $this->actingAs($user)->post(
            route('website.store.checkout.store', [$resellerA->slug, $product->id]),
            ['idempotency_token' => 'cross-store-1'],
        );
        $order = Order::query()->where('customer_account_id', $customerA->id)->firstOrFail();

        $this->actingAs($user)
            ->get(route('website.store.orders.show', [$resellerB->slug, $order->id]))
            ->assertNotFound();
    }

    #[Test]
    public function a_product_the_reseller_never_enabled_cannot_be_bought_even_with_a_crafted_request(): void
    {
        $product = $this->makeProduct();
        $reseller = Reseller::factory()->create(['status' => 'active']); // هیچ ResellerProductPrice ای ندارد

        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->wallet->credit($customer, 500000);
        $this->wallet->credit($reseller, 500000);

        $this->actingAs($user)->post(
            route('website.store.checkout.store', [$reseller->slug, $product->id]),
            ['idempotency_token' => 'not-enabled-1'],
        )->assertNotFound();

        $this->assertEquals(0, Order::query()->count());
        $this->assertEquals(500000, $this->wallet->getBalance($customer));
        $this->assertEquals(500000, $this->wallet->balance($reseller));
    }

    #[Test]
    public function checkout_is_blocked_and_nothing_is_debited_when_the_resellers_supply_wallet_is_empty(): void
    {
        $product = $this->makeProduct(resellerPrice: 100000);
        $reseller = $this->resellerSelling($product, customersPrice: 130000);

        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->wallet->credit($customer, 200000);
        // Wallet صاحبِ نماینده در Main عمداً خالی می‌ماند (و debt_limit پیش‌فرض صفر است)

        $response = $this->actingAs($user)->post(
            route('website.store.checkout.store', [$reseller->slug, $product->id]),
            ['idempotency_token' => 'supply-empty-1'],
        );

        $response->assertSessionHasErrors('checkout');
        $this->assertEquals(0, Order::query()->count());
        $this->assertEquals(200000, $this->wallet->getBalance($customer), 'مشتری نباید کسر شده باشد');
        $this->assertEquals(0, $this->wallet->balance($reseller));
    }

    #[Test]
    public function an_inactive_reseller_store_does_not_exist_for_visitors(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'inactive']);

        $this->get(route('website.store.home', $reseller->slug))->assertNotFound();
    }

    #[Test]
    public function an_unknown_store_slug_is_a_plain_not_found(): void
    {
        $this->get('/store/this-slug-does-not-exist')->assertNotFound();
    }

    #[Test]
    public function resubmitting_the_same_token_in_a_reseller_store_charges_neither_wallet_twice(): void
    {
        $product = $this->makeProduct(resellerPrice: 100000);
        $reseller = $this->resellerSelling($product, customersPrice: 130000);

        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->wallet->credit($customer, 400000);
        $this->wallet->credit($reseller, 500000);

        foreach ([1, 2] as $_) {
            $this->actingAs($user)->post(
                route('website.store.checkout.store', [$reseller->slug, $product->id]),
                ['idempotency_token' => 'same-token-in-reseller-store'],
            );
        }

        $this->assertEquals(1, Order::query()->where('customer_account_id', $customer->id)->count());
        $this->assertEquals(270000, $this->wallet->getBalance($customer));
        $this->assertEquals(400000, $this->wallet->balance($reseller));
    }
}
