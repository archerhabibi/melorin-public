<?php

namespace Tests\Feature\Resellers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProvisioningSetting;
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
use App\Models\CustomerAccount;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;

/**
 * طبق درخواست صریح: «قیمت نمایندگان» قیمتی است که پلتفرم به نماینده
 * می‌فروشد (Double-Debit reseller_price) — نه قیمت پایه‌ی خرده‌فروشی که
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

    protected function resellerCustomerAccount(User $customer, Reseller $reseller): CustomerAccount
    {
        return app(IdentityService::class)->resolveCustomerAccount(
            $customer,
            StoreContext::fromReseller($reseller),
        );
    }

    protected function sellableProduct(Reseller $reseller, float $price, ?float $resellerPrice, float $customersPrice): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $price,
            'reseller_price' => $resellerPrice,
        ]);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
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
        $customer = User::factory()->create();

        $product = $this->sellableProduct($reseller, price: 150000, resellerPrice: 90000, customersPrice: 140000);

        $wallet = app(WalletService::class);
        $customerAccount = $this->resellerCustomerAccount($customer, $reseller);

        $wallet->charge($customerAccount, 200000);
        $wallet->charge($reseller, 200000);

        $account = app(AccountService::class)->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        $this->assertEquals(60000, $wallet->balance($customerAccount));
        $this->assertEquals(110000, $wallet->balance($reseller));

        $this->assertEquals(90000, $account->order->reseller_price);
        $this->assertEquals(140000, $account->order->customers_price);
    }

    #[Test]
    public function without_a_reseller_price_the_retail_price_is_still_used_as_before(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create();
        $product = $this->sellableProduct($reseller, price: 100000, resellerPrice: null, customersPrice: 130000);

        $wallet = app(WalletService::class);
        $customerAccount = $this->resellerCustomerAccount($customer, $reseller);

        $wallet->charge($customerAccount, 200000);
        $wallet->charge($reseller, 200000);

        $account = app(AccountService::class)->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        $this->assertEquals(100000, $wallet->balance($reseller));
        $this->assertEquals(100000, $account->order->reseller_price);
    }

    #[Test]
    public function failed_account_creation_refunds_exactly_the_wholesale_price_that_was_debited(): void
    {
        // این تست عمداً سیاست را refund می‌گذارد تا واقعاً بازگشت وجه
        // اتفاق بیفتد و بشود بررسی کرد بازگشت از resellerPrice() استفاده
        // می‌کند نه main_price. سیاست پیش‌فرض سیستم از فاز ۱۱ به بعد
        // retry است (بند ۳۶ — بدون بازگشت خودکار، چون ممکن است اکانت
        // واقعاً روی پنل ساخته شده باشد)، پس بدون این خط اصلاً بازگشتی
        // رخ نمی‌داد و این تست چیزی را که می‌خواست بسنجد اصلاً امتحان
        // نمی‌کرد.
        ProvisioningSetting::current()->update(['failure_policy' => ProvisioningSetting::POLICY_REFUND]);

        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response([], 500),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create();
        $product = $this->sellableProduct($reseller, price: 150000, resellerPrice: 90000, customersPrice: 140000);

        $wallet = app(WalletService::class);
        $customerAccount = $this->resellerCustomerAccount($customer, $reseller);

        $wallet->charge($customerAccount, 200000);
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

        // اگر بازگشت وجه به‌جای resellerPrice() از main_price
        // (150,000) استفاده می‌کرد، اینجا موجودی نماینده ۲۱۰,۰۰۰
        // می‌شد — یعنی ۱۰,۰۰۰ بیشتر از چیزی که واقعاً کسر شده بود.
        $this->assertEquals(200000, $wallet->balance($customerAccount));
        $this->assertEquals(200000, $wallet->balance($reseller));
    }

    #[Test]
    public function reseller_can_sell_profitably_between_the_wholesale_price_and_the_retail_price(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['main_price' => 150000, 'reseller_price' => 90000]);

        $setting = app(ResellerPricingService::class)->setCustomersPrice($reseller, $product, 120000);

        $this->assertEquals(120000, $setting->customers_price);
    }

    #[Test]
    public function customers_price_below_the_wholesale_reseller_price_is_still_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $product = Product::factory()->create(['main_price' => 150000, 'reseller_price' => 90000]);

        $this->expectException(InvalidArgumentException::class);

        app(ResellerPricingService::class)->setCustomersPrice($reseller, $product, 80000);
    }
}
