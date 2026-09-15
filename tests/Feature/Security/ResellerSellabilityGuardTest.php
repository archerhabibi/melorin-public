<?php

namespace Tests\Feature\Security;

use App\Exceptions\ProductNotSellableException;
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
 * تست‌های رگرسیونِ P0 — گزارش امنیتی v3.0.6.
 *
 * چرا این فایل جدا از تست‌های عادی است: هر تست اینجا یک حفره‌ی واقعیِ
 * تأییدشده را می‌بندد. اگر روزی یکی از این‌ها قرمز شد، یعنی همان حفره
 * برگشته — نه اینکه «یک فیچر کار نمی‌کند». طبق درس گرفته‌شده از خودِ
 * گزارش («بعضی Fixهای قبلی regression کرده‌اند»)، این تست‌ها هرگز نباید
 * حذف یا نرم شوند.
 */
class ResellerSellabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSellableProduct(Reseller $reseller, array $categoryAttributes = []): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(array_merge([
            'status' => 'active',
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

    protected function fundedCustomer(Reseller $reseller): User
    {
        $customer = User::factory()->create(['reseller_id' => $reseller->id]);
        app(WalletService::class)->charge($customer, 500000);
        app(WalletService::class)->charge($reseller, 500000);

        return $customer;
    }

    protected function fakePanelApi(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);
    }

    /**
     * Critical #1 — حفره‌ی اصلی: سبدی که خودِ نماینده بسته، از طریق یک
     * callback دست‌ساز («rbuy:product:123») همچنان قابل خرید بود، چون
     * AccountService فقط قیمت را چک می‌کرد نه sellability را.
     */
    /** @test */
    public function a_crafted_purchase_from_a_category_the_reseller_disabled_is_rejected_in_core(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller);
        $customer = $this->fundedCustomer($reseller);

        app(ResellerPricingService::class)->setCategoryEnabled($reseller, $product->category, false);

        $this->expectException(ProductNotSellableException::class);

        app(AccountService::class)->purchase(
            $customer, $product, salesChannel: 'reseller_bot', reseller: $reseller,
        );
    }

    /** Critical #2 — همان حفره، این بار برای تصمیم سراسریِ Core. */
    /** @test */
    public function a_crafted_purchase_from_a_category_core_closed_to_resellers_is_rejected(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller, ['available_to_resellers' => false]);
        $customer = $this->fundedCustomer($reseller);

        $this->expectException(ProductNotSellableException::class);

        app(AccountService::class)->purchase(
            $customer, $product, salesChannel: 'reseller_bot', reseller: $reseller,
        );
    }

    /** Critical #3 — نمایندگی غیرفعال نباید بتواند بفروشد، حتی با درخواست مستقیم به Core. */
    /** @test */
    public function an_inactive_reseller_cannot_complete_a_purchase(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller);
        $customer = $this->fundedCustomer($reseller);

        $reseller->update(['status' => 'inactive']);

        $this->expectException(ProductNotSellableException::class);

        app(AccountService::class)->purchase(
            $customer, $product->fresh(), salesChannel: 'reseller_bot', reseller: $reseller->fresh(),
        );
    }

    /** @test */
    public function a_disabled_product_cannot_be_purchased_even_with_a_valid_price_row(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller);
        $customer = $this->fundedCustomer($reseller);

        $product->update(['status' => 'inactive']);

        $this->expectException(ProductNotSellableException::class);

        app(AccountService::class)->purchase(
            $customer, $product->fresh(), salesChannel: 'reseller_bot', reseller: $reseller,
        );
    }

    /**
     * ضدآزمون: مطمئن شویم این محافظ‌ها مسیر سالم را نشکسته‌اند — وگرنه
     * ممکن بود همه‌ی تست‌های بالا صرفاً به این دلیل سبز باشند که خرید
     * اصلاً دیگر کار نمی‌کند.
     */
    /** @test */
    public function a_fully_valid_reseller_purchase_still_succeeds(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller);
        $customer = $this->fundedCustomer($reseller);

        $account = app(AccountService::class)->purchase(
            $customer, $product, salesChannel: 'reseller_bot', reseller: $reseller,
        );

        $this->assertEquals(90000, $account->order->base_price);
        $this->assertEquals(120000, $account->order->sold_price);
    }

    /**
     * هیچ‌کدام از ردهای بالا نباید پولی از کیف پول کم کرده باشد —
     * «No orphan debit». چون assertSellable قبل از هر کسری اجرا
     * می‌شود، موجودی‌ها باید عیناً دست‌نخورده بمانند.
     */
    /** @test */
    public function a_rejected_purchase_leaves_both_wallets_untouched(): void
    {
        $this->fakePanelApi();

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeSellableProduct($reseller);
        $customer = $this->fundedCustomer($reseller);

        app(ResellerPricingService::class)->setCategoryEnabled($reseller, $product->category, false);

        try {
            app(AccountService::class)->purchase(
                $customer, $product, salesChannel: 'reseller_bot', reseller: $reseller,
            );
        } catch (ProductNotSellableException) {
            // انتظار می‌رود
        }

        $wallet = app(WalletService::class);
        $this->assertEquals(500000, $wallet->balance($customer->fresh()));
        $this->assertEquals(500000, $wallet->balance($reseller->fresh()));
    }
}
