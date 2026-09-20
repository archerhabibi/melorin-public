<?php

namespace Tests\Feature\Resellers;

use App\Exceptions\InsufficientBalanceException;
use App\Services\Core\Purchase\ResellerDebtLimitException;
use App\Exceptions\ResellerScopeViolationException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProvisioningSetting;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * پوشش دقیق «Acceptance Tests» بخش ۲۵ سند Melorin-Reseller-Platform-Spec:
 * قرارداد مالیِ Double-Debit (بند ۶ و ۷) و شکست/جبران (بند ۸).
 */
class ResellerPurchaseFinancialTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    protected AccountService $accounts;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(AccountService::class);
        $this->wallet = app(WalletService::class);
    }

    protected function makeCategoryWithPanel(): Category
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        return $category;
    }

    protected function sellableProduct(Reseller $reseller, float $mainPrice, float $customersPrice): Product
    {
        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => $mainPrice]);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        return $product;
    }

    protected function fakeSuccessfulPanel(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test', 'subscription_url' => 'https://sub.example.com/x'], 200),
        ]);
    }

    #[Test]
    public function purchase_debits_customer_by_customers_price_and_reseller_by_reseller_price(): void
    {
        $this->fakeSuccessfulPanel();

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        $this->wallet->charge($this->accountIn($customer, $reseller), 20);
        $this->wallet->charge($reseller, 30);

        $account = $this->accounts->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        // طبق بند ۶ سند: Customer Purchase Debit = customers_price
        $this->assertEquals(6, $this->wallet->balance($this->accountIn($customer, $reseller)));
        // Reseller Purchase Debit = reseller_price
        $this->assertEquals(20, $this->wallet->balance($reseller));

        $order = $account->order;
        $this->assertEquals(10, $order->reseller_price);
        $this->assertEquals(14, $order->customers_price);
        $this->assertEquals(4, $order->resellerProfit());
        $this->assertEquals($reseller->id, $order->reseller_id);
        $this->assertEquals('reseller_bot', $order->sales_channel);
        $this->assertEquals($customer->id, $account->user_id);
    }

    #[Test]
    public function insufficient_customer_balance_blocks_purchase_without_touching_the_owners_main_wallet(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        // مشتری هیچ موجودی‌ای ندارد؛ نماینده موجودی کافی دارد
        $this->wallet->charge($reseller, 100);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
        } finally {
            $this->assertEquals(0, $this->wallet->balance($this->accountIn($customer, $reseller)));
            $this->assertEquals(100, $this->wallet->balance($reseller));
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function insufficient_reseller_balance_blocks_purchase_without_touching_the_customers_wallet(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        // مشتری موجودی کافی دارد؛ نماینده هیچ اعتباری ندارد
        $this->wallet->charge($this->accountIn($customer, $reseller), 100);

        // طبق بند ۴۶ سند: کمبود اعتبار نماینده مفهوماً از کمبود موجودی
        // مشتری جداست، پس استثنای اختصاصی خودش را دارد (نه
        // InsufficientBalanceException عمومی) — همان چیزی که این تست
        // خودش دارد اسمش را چک می‌کند.
        $this->expectException(ResellerDebtLimitException::class);

        try {
            $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
        } finally {
            $this->assertEquals(100, $this->wallet->balance($this->accountIn($customer, $reseller)));
            $this->assertEquals(0, $this->wallet->balance($reseller));
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function panel_failure_refunds_both_customer_and_reseller(): void
    {
        // سیاست پیش‌فرض از فاز ۱۱ به بعد retry است (بدون بازگشت خودکار)؛
        // این تست دقیقاً بازگشتِ دوطرفه را می‌سنجد، پس باید صریحاً
        // سیاست را refund بگذارد تا واقعاً بازگشتی اتفاق بیفتد.
        ProvisioningSetting::current()->update(['failure_policy' => ProvisioningSetting::POLICY_REFUND]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['detail' => 'username already exists'], 409),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        $this->wallet->charge($this->accountIn($customer, $reseller), 20);
        $this->wallet->charge($reseller, 30);

        $this->expectException(\RuntimeException::class);

        try {
            $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
        } finally {
            // طبق بند ۸ سند: «No lost money, no double charge, no orphan debit»
            $this->assertEquals(20, $this->wallet->balance($this->accountIn($customer, $reseller)));
            $this->assertEquals(30, $this->wallet->balance($reseller));
        }
    }

    #[Test]
    public function product_not_enabled_by_reseller_is_not_sellable_even_if_globally_active(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => 10, 'status' => 'active']);
        // عمداً هیچ ResellerProductPrice ای ساخته نمی‌شود — یعنی نماینده هرگز آن را فعال نکرده

        $this->wallet->charge($this->accountIn($customer, $reseller), 100);
        $this->wallet->charge($reseller, 100);

        $this->expectException(\RuntimeException::class);

        try {
            $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
        } finally {
            $this->assertEquals(100, $this->wallet->balance($this->accountIn($customer, $reseller)));
            $this->assertEquals(100, $this->wallet->balance($reseller));
        }
    }

    #[Test]
    public function disabling_product_globally_blocks_sale_even_if_reseller_enabled_it(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $customer = $this->memberOf($reseller);
        // نماینده محصول را فعال کرده، ولی سیستم اصلی محصول را غیرفعال می‌کند
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);
        $product->update(['status' => 'inactive']);

        $this->wallet->charge($this->accountIn($customer, $reseller), 100);
        $this->wallet->charge($reseller, 100);

        $this->expectException(\RuntimeException::class);
        $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
    }

    #[Test]
    public function customer_not_belonging_to_this_reseller_cannot_purchase_through_it(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $otherReseller = Reseller::factory()->create();
        // این مشتری متعلق به نماینده‌ی دیگری است
        $customer = $this->memberOf($otherReseller);
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        $this->wallet->charge($reseller, 100);

        $this->expectException(ResellerScopeViolationException::class);
        $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
    }

    #[Test]
    public function a_customer_with_no_reseller_at_all_cannot_purchase_through_a_reseller(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create();
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        $this->wallet->charge($reseller, 100);

        $this->expectException(ResellerScopeViolationException::class);
        $this->accounts->purchase($customer, $product, salesChannel: 'reseller_bot', reseller: $reseller);
    }

    #[Test]
    public function main_bot_purchase_is_unaffected_and_still_debits_only_the_customer(): void
    {
        $this->fakeSuccessfulPanel();

        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => 100000]);
        $user = User::factory()->create();

        $this->wallet->charge($user, 150000);

        $account = $this->accounts->purchase($user, $product);

        $this->assertEquals(50000, $this->wallet->balance($user));
        $this->assertNull($account->order->reseller_id);
        $this->assertEquals('main_bot', $account->order->sales_channel);
    }
}
