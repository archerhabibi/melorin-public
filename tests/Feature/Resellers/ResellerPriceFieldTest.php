<?php

namespace Tests\Feature\Resellers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * طبق درخواست صریح: «قیمت نمایندگان» قیمتی است که پلتفرم به نماینده
 * می‌فروشد (Double-Debit base_price) — نه قیمت پایه‌ی خرده‌فروشی که
 * تا این نسخه اشتباهاً از کیف‌پول نماینده هم کسر می‌شد. این تست‌ها هم
 * مسیر خرید (AccountService) و هم قوانین قیمت‌گذاری
 * (ResellerPricingService::assertPriceAllowed) را با reseller_price
 * تنظیم‌شده پوشش می‌دهند — علاوه‌بر تست‌های قبلیِ ResellerPurchaseFinancialTest/
 * ResellerCoreServicesTest که بدون reseller_price (یعنی fallback به
 * products.price) هنوز هم دست‌نخورده و سبز باقی می‌مانند.
 */
class ResellerPriceFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function sellableProduct(Reseller $reseller, float $price, ?float $resellerPrice, float $sellingPrice): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => $price,
            'reseller_price' => $resellerPrice,
        ]);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'custom_price' => $sellingPrice,
            'is_enabled' => true,
        ]);

        return $product;
    }

    #[Test]
    public function purchase_debits_the_reseller_by_the_wholesale_reseller_price_not_the_retail_price(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);

        $product = $this->sellableProduct($reseller, price: 150000, resellerPrice: 90000, sellingPrice: 140000);

        $wallet = app(WalletService::class);
        $wallet->charge($customer, 200000);
        $wallet->charge($reseller, 200000);

        $account = app(AccountService::class)->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        $this->assertEquals(60000, $wallet->balance($customer));
        $this->assertEquals(110000, $wallet->balance($reseller));

        $this->assertEquals(90000, $account->order->base_price);
        $this->assertEquals(140000, $account->order->sold_price);
    }

    #[Test]
    public function without_a_reseller_price_the_retail_price_is_still_used_as_before(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = $this->sellableProduct($reseller, price: 100000, resellerPrice: null, sellingPrice: 130000);

        $wallet = app(WalletService::class);
        $wallet->charge($customer, 200000);
        $wallet->charge($reseller, 200000);

        $account = app(AccountService::class)->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        $this->assertEquals(100000, $wallet->balance($reseller));
        $this->assertEquals(100000, $account->order->base_price);
    }

    #[Test]
    public function failed_account_creation_refunds_exactly_the_wholesale_price_that_was_debited(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response([], 500),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = $this->sellableProduct($reseller, price: 150000, resellerPrice: 90000, sellingPrice: 140000);

        $wallet = app(WalletService::class);
        $wallet->charge($customer, 200000);
        $wallet->charge($reseller, 200000);

        try {
            app(AccountService::class)->purchase(
                $customer,
                $product,
                salesChannel: 'reseller_bot',
                reseller: $reseller,
            );
        } catch (\RuntimeException) {
            // انتظار می‌رود.
        }

        // اگر بازگشت وجه به‌جای resellerBasePrice() از products.price
        // (150,000) استفاده می‌کرد، اینجا موجودی نماینده ۲۱۰,۰۰۰
        // می‌شد — یعنی ۱۰,۰۰۰ بیشتر از چیزی که واقعاً کسر شده بود.
        $this->assertEquals(200000, $wallet->balance($customer));
        $this->assertEquals(200000, $wallet->balance($reseller));
    }

    #[Test]
    public function reseller_can_sell_profitably_between_the_wholesale_price_and_the_retail_price(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['price' => 150000, 'reseller_price' => 90000]);

        $setting = app(ResellerPricingService::class)->setSellingPrice($reseller, $product, 120000);

        $this->assertEquals(120000, $setting->custom_price);
    }

    #[Test]
    public function selling_price_below_the_wholesale_reseller_price_is_still_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['price' => 150000, 'reseller_price' => 90000]);

        $this->expectException(InvalidArgumentException::class);

        app(ResellerPricingService::class)->setSellingPrice($reseller, $product, 80000);
    }
}
