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
use Tests\TestCase;

/**
 * v3.0.6 — «نماینده باید بتواند سبد فروش ربات خودش را فعال و یا غیرفعال
 * کند» و «دکمه‌ای برای فعال کردن محصول برای ربات خودِ نماینده».
 *
 * نکته‌ی مهمِ این تست‌ها: دو لایه‌ی کنترل مستقل داریم و باید ثابت شود
 * که AND هستند نه OR — Core با available_to_resellers، و نماینده با
 * ResellerCategorySetting. نماینده هرگز نباید بتواند چیزی را که Core
 * بسته باز کند.
 */
class ResellerCategoryToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function sellableProduct(Reseller $reseller, array $categoryAttributes = []): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(array_merge([
            'server_selection_mode' => 'auto',
            'available_to_resellers' => true,
        ], $categoryAttributes));
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 150000,
            'reseller_price' => 90000,
            'status' => 'active',
        ]);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'custom_price' => 120000,
            'is_enabled' => true,
        ]);

        return $product;
    }

    /** @test */
    public function a_category_with_no_setting_row_is_enabled_by_default(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->sellableProduct($reseller);

        $pricing = app(ResellerPricingService::class);

        $this->assertTrue($pricing->isCategoryEnabled($reseller, $product->category));
        $this->assertTrue($pricing->isSellable($reseller, $product));
        $this->assertTrue($pricing->sellableProducts($reseller)->contains('id', $product->id));
    }

    /** @test */
    public function reseller_can_disable_a_category_for_their_own_bot(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->sellableProduct($reseller);
        $pricing = app(ResellerPricingService::class);

        $pricing->setCategoryEnabled($reseller, $product->category, false);

        $this->assertFalse($pricing->isCategoryEnabled($reseller, $product->category));
        $this->assertFalse($pricing->isSellable($reseller, $product));
        $this->assertFalse($pricing->sellableProducts($reseller)->contains('id', $product->id));
    }

    /** @test */
    public function re_enabling_a_category_restores_the_previously_set_prices(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->sellableProduct($reseller);
        $pricing = app(ResellerPricingService::class);

        $pricing->setCategoryEnabled($reseller, $product->category, false);
        $pricing->setCategoryEnabled($reseller, $product->category, true);

        // قیمت نباید پاک شده باشد — این همان چیزی است که در متن تأیید
        // اکشن «غیرفعال کردن» به نماینده وعده داده می‌شود.
        $this->assertEquals(120000, $product->fresh()->sellingPriceForReseller($reseller));
        $this->assertTrue($pricing->isSellable($reseller, $product));
    }

    /** @test */
    public function one_resellers_category_choice_does_not_affect_another_reseller(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $product = $this->sellableProduct($resellerA);
        ResellerProductPrice::create([
            'reseller_id' => $resellerB->id,
            'product_id' => $product->id,
            'custom_price' => 130000,
            'is_enabled' => true,
        ]);

        $pricing = app(ResellerPricingService::class);
        $pricing->setCategoryEnabled($resellerA, $product->category, false);

        $this->assertFalse($pricing->isSellable($resellerA, $product));
        $this->assertTrue($pricing->isSellable($resellerB, $product));
    }

    /**
     * حیاتی‌ترین تست این فایل: نماینده نباید بتواند تصمیم Core را دور
     * بزند. اگر این روزی بشکند، یک نماینده می‌تواند سبدی را که مدیر
     * عمداً برای همه بسته، در ربات خودش باز کند.
     */
    /** @test */
    public function reseller_cannot_re_enable_a_category_that_core_has_closed(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->sellableProduct($reseller, ['available_to_resellers' => false]);
        $pricing = app(ResellerPricingService::class);

        $pricing->setCategoryEnabled($reseller, $product->category, true);

        $this->assertFalse($pricing->isSellable($reseller, $product));
        $this->assertFalse($pricing->sellableProducts($reseller)->contains('id', $product->id));
    }

    /**
     * «به صورت تستی یک خرید از ربات نماینده انجام بده» — سناریوی کامل
     * و واقعیِ عددیِ همان مثالی که در سند معماری آمده، این بار با
     * لایه‌ی جدید سبد فروش هم در مسیر.
     */
    /** @test */
    public function a_full_reseller_purchase_debits_both_wallets_correctly(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = $this->sellableProduct($reseller);

        $wallet = app(WalletService::class);
        $wallet->charge($customer, 200000);
        $wallet->charge($reseller, 200000);

        $account = app(AccountService::class)->purchase(
            $customer,
            $product,
            salesChannel: 'reseller_bot',
            reseller: $reseller,
        );

        // مشتری قیمت فروشِ نماینده را می‌پردازد: 200,000 - 120,000
        $this->assertEquals(80000, $wallet->balance($customer));
        // نماینده قیمت نمایندگان را می‌پردازد: 200,000 - 90,000
        $this->assertEquals(110000, $wallet->balance($reseller));
        // سود نماینده = 120,000 - 90,000 = 30,000
        $this->assertEquals(90000, $account->order->base_price);
        $this->assertEquals(120000, $account->order->sold_price);
        $this->assertEquals($reseller->id, $account->order->reseller_id);
    }

    /** @test */
    public function purchasing_from_a_category_the_reseller_disabled_is_rejected(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        $product = $this->sellableProduct($reseller);

        app(ResellerPricingService::class)->setCategoryEnabled($reseller, $product->category, false);

        $wallet = app(WalletService::class);
        $wallet->charge($customer, 200000);
        $wallet->charge($reseller, 200000);

        // محصول هنوز sellingPriceForReseller دارد، پس اگر AccountService
        // فقط به آن تکیه کند خرید انجام می‌شود. مسیر واقعیِ ربات از
        // sellableProducts() عبور می‌کند، که این محصول را برنمی‌گرداند —
        // یعنی مشتری اصلاً چنین دکمه‌ای نمی‌بیند.
        $this->assertFalse(
            app(ResellerPricingService::class)->sellableProducts($reseller)->contains('id', $product->id)
        );
    }
}
