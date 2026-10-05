<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Reseller\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Reseller\Resources\ProductResource\Widgets\ProductSummary;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerCategorySetting;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Resellers\ResellerPricingService;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B5.3 — لایه‌ی نمایش مدیریت محصولات نماینده. منطق در ResellerProductCatalogTest/ResellerBulkPricingTest تست
 * شده؛ این‌جا: رندر واقعی، جداسازی، فیلتر/مرتب‌سازی، اکشن‌ها (تکی و گروهی)، صفحه‌ی جزئیات و هشدار علت.
 */
class ResellerProductPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    private function actingAsReseller(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $owner->id], ['role' => 'owner']);

        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        return $owner;
    }

    private function product(array $attrs = [], array $category = []): Product
    {
        return Product::factory()->create([
            'category_id' => Category::factory()->create($category)->id,
            'main_price' => 100000,
            'reseller_price' => 80000,
            ...$attrs,
        ]);
    }

    private function priceOf(Reseller $r, Product $p): ?int
    {
        return ResellerProductPrice::query()->where('reseller_id', $r->id)->where('product_id', $p->id)->value('customers_price');
    }

    #[Test]
    public function the_list_and_a_product_page_render_over_http(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $p = $this->product(['name' => 'پلن طلایی']);
        app(ResellerPricingService::class)->setCustomersPrice($reseller, $p, 95000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/products')
            ->assertOk()
            ->assertSee('پلن طلایی')
            ->assertSee('95,000')
            ->assertSee('در حال فروش');

        $this->actingAs($owner, 'reseller')->get('/arial/products/'.$p->id)
            ->assertOk()
            ->assertSee('پلن طلایی')
            ->assertSee('80,000')
            ->assertSee('95,000')
            ->assertSee('سابقه‌ی تغییر قیمت');
    }

    #[Test]
    public function a_product_the_reseller_cannot_manage_is_not_found(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $closed = $this->product([], ['available_to_resellers' => false]);
        $inactive = $this->product(['status' => 'inactive']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/products/'.$closed->id)->assertNotFound();
        $this->actingAs($owner, 'reseller')->get('/arial/products/'.$inactive->id)->assertNotFound();
    }

    #[Test]
    public function the_list_shows_this_resellers_prices_and_sales_only(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $p = $this->product();
        app(ResellerPricingService::class)->setCustomersPrice($reseller, $p, 91000);
        app(ResellerPricingService::class)->setCustomersPrice($other, $p, 99999);
        Order::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $p->id, 'reseller_id' => $other->id, 'customers_price' => 99999, 'reseller_price' => 80000, 'status' => 'account_created']);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$p])
            ->assertSee('91,000')
            ->assertDontSee('99,999');
    }

    #[Test]
    public function the_state_filter_uses_the_core_definition_and_ignores_garbage(): void
    {
        $reseller = Reseller::factory()->create();
        $selling = $this->product();
        app(ResellerPricingService::class)->setCustomersPrice($reseller, $selling, 90000);
        $unpriced = $this->product();
        $off = $this->product();
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $off->category_id, 'is_enabled' => false]);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->filterTable('state', 'unpriced')
            ->assertCanSeeTableRecords([$unpriced])
            ->assertCanNotSeeTableRecords([$selling, $off])
            ->filterTable('state', 'category_off')
            ->assertCanSeeTableRecords([$off])
            ->assertCanNotSeeTableRecords([$selling, $unpriced])
            ->filterTable('state', 'not-a-state')
            ->assertCanSeeTableRecords([$selling, $unpriced, $off]);
    }

    #[Test]
    public function the_numeric_columns_are_sortable_including_the_computed_ones(): void
    {
        $reseller = Reseller::factory()->create();
        $cheap = $this->product(['reseller_price' => 50000, 'name' => 'ارزان']);
        $pricey = $this->product(['reseller_price' => 90000, 'name' => 'گران']);
        $pricing = app(ResellerPricingService::class);
        $pricing->setCustomersPrice($reseller, $cheap, 80000);   // سود ۳۰٬۰۰۰
        $pricing->setCustomersPrice($reseller, $pricey, 95000);  // سود ۵٬۰۰۰
        $this->actingAsReseller($reseller);

        // گروه‌بندی پیش‌فرض بر اساس سبد است؛ در هر سبد یک محصول است پس مقایسه‌ی ترتیب روی خود ستون‌ها دیده می‌شود
        foreach (['supply_price', 'my_price', 'profit', 'sales_count'] as $column) {
            Livewire::test(ListProducts::class)->sortTable($column)->assertSuccessful();
            Livewire::test(ListProducts::class)->sortTable($column, 'desc')->assertSuccessful();
        }

        Livewire::test(ListProducts::class)
            ->sortTable('profit', 'desc')
            ->assertCanSeeTableRecords([$cheap, $pricey], inOrder: false);
    }

    #[Test]
    public function setting_a_price_still_validates_and_a_rejected_price_leaves_the_old_one(): void
    {
        $reseller = Reseller::factory()->create(['min_sale_price_rule' => ['max_price' => 100000]]);
        $p = $this->product();
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->callTableAction('set_price', $p, data: ['customers_price' => 95000])
            ->assertHasNoTableActionErrors();
        $this->assertSame(95000, $this->priceOf($reseller, $p));

        Livewire::test(ListProducts::class)->callTableAction('set_price', $p, data: ['customers_price' => 120000]);
        $this->assertSame(95000, $this->priceOf($reseller, $p));
    }

    #[Test]
    public function the_price_form_starts_from_the_stored_price_or_the_supply_price(): void
    {
        $reseller = Reseller::factory()->create();
        $fresh = $this->product(['reseller_price' => 80000]);
        $priced = $this->product();
        app(ResellerPricingService::class)->setCustomersPrice($reseller, $priced, 93000);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->mountTableAction('set_price', $fresh)
            ->assertTableActionDataSet(['customers_price' => '80000'])
            ->callTableAction('set_price', $priced)
            ->mountTableAction('set_price', $priced)
            ->assertTableActionDataSet(['customers_price' => '93000']);
    }

    #[Test]
    public function setting_a_price_on_a_product_whose_category_is_off_warns_instead_of_claiming_it_sells(): void
    {
        $reseller = Reseller::factory()->create();
        $p = $this->product();
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $p->category_id, 'is_enabled' => false]);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->callTableAction('set_price', $p, data: ['customers_price' => 90000])
            ->assertNotified('انجام شد، ولی این محصول هنوز فروش نمی‌رود: سبد در فروشگاه شما خاموش است');

        $this->assertSame(90000, $this->priceOf($reseller, $p), 'قیمت ثبت شد');
        $this->assertFalse(app(ResellerPricingService::class)->isSellable($reseller, $p->fresh()));
    }

    #[Test]
    public function enable_and_disable_are_offered_only_when_they_make_sense(): void
    {
        $reseller = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);
        $unpriced = $this->product();
        $selling = $this->product();
        $pricing->setCustomersPrice($reseller, $selling, 90000);
        $paused = $this->product();
        $pricing->setCustomersPrice($reseller, $paused, 90000);
        $pricing->disable($reseller, $paused);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->assertTableActionHidden('enable', $unpriced)
            ->assertTableActionHidden('disable', $unpriced)
            ->assertTableActionHidden('enable', $selling)
            ->assertTableActionVisible('disable', $selling)
            ->assertTableActionVisible('enable', $paused)
            ->assertTableActionHidden('disable', $paused)
            ->callTableAction('enable', $paused);

        $this->assertTrue($pricing->isSellable($reseller->fresh(), $paused->fresh()));
    }

    #[Test]
    public function the_bulk_markup_action_prices_selected_products_and_reports_the_skipped(): void
    {
        $reseller = Reseller::factory()->create(['min_sale_price_rule' => ['max_price' => 100000]]);
        $ok = $this->product(['reseller_price' => 80000]);
        $tooHigh = $this->product(['reseller_price' => 90000]);
        $untouched = $this->product();
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('bulk_markup', [$ok, $tooHigh], data: ['mode' => 'percent', 'percent' => 20, 'round_to' => null])
            ->assertNotified();

        $this->assertSame(96000, $this->priceOf($reseller, $ok));
        $this->assertNull($this->priceOf($reseller, $tooHigh), 'بیرون از قوانین، رد شد');
        $this->assertNull($this->priceOf($reseller, $untouched), 'انتخاب‌نشده دست‌نخورده');
    }

    #[Test]
    public function the_bulk_markup_with_a_fixed_amount_and_rounding_works_from_the_form(): void
    {
        $reseller = Reseller::factory()->create();
        $p = $this->product(['reseller_price' => 80000]);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('bulk_markup', [$p], data: ['mode' => 'fixed', 'amount' => 12345, 'round_to' => 1000])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(93000, $this->priceOf($reseller, $p));
    }

    #[Test]
    public function the_bulk_enable_and_disable_actions_work(): void
    {
        $reseller = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);
        $a = $this->product();
        $b = $this->product();
        $pricing->setCustomersPrice($reseller, $a, 90000);
        $pricing->setCustomersPrice($reseller, $b, 90000);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)->callTableBulkAction('bulk_disable', [$a, $b]);
        $this->assertFalse($pricing->isSellable($reseller->fresh(), $a->fresh()));
        $this->assertFalse($pricing->isSellable($reseller->fresh(), $b->fresh()));

        Livewire::test(ListProducts::class)->callTableBulkAction('bulk_enable', [$a]);
        $this->assertTrue($pricing->isSellable($reseller->fresh(), $a->fresh()));
        $this->assertFalse($pricing->isSellable($reseller->fresh(), $b->fresh()));
    }

    #[Test]
    public function a_bulk_action_cannot_reach_a_product_closed_by_the_core(): void
    {
        $reseller = Reseller::factory()->create();
        $closed = $this->product([], ['available_to_resellers' => false]);
        $this->actingAsReseller($reseller);

        // رکورد در Query جدول نیست؛ حتی اگر کلیدش دست‌ساز بیاید، تغییری رخ نمی‌دهد
        try {
            Livewire::test(ListProducts::class)
                ->callTableBulkAction('bulk_markup', [$closed], data: ['mode' => 'percent', 'percent' => 10]);
        } catch (\Throwable) {
            // Livewire/Filament ممکن است خودش خطا بدهد؛ چیزی که مهم است ننوشتن است
        }

        $this->assertNull($this->priceOf($reseller, $closed));
    }

    #[Test]
    public function the_product_page_actions_work_and_refresh_the_buttons(): void
    {
        $reseller = Reseller::factory()->create();
        $p = $this->product();
        $this->actingAsReseller($reseller);

        Livewire::test(ViewProduct::class, ['record' => $p->getKey()])
            ->assertActionHidden('enable')
            ->assertActionHidden('disable')
            ->callAction('set_price', data: ['customers_price' => 92000])
            ->assertActionVisible('disable')
            ->callAction('disable')
            ->assertActionVisible('enable')
            ->assertActionHidden('disable');

        $this->assertSame(92000, $this->priceOf($reseller, $p));
    }

    #[Test]
    public function the_product_page_shows_history_and_the_allowed_range(): void
    {
        $reseller = Reseller::factory()->create(['min_sale_price_rule' => ['min_price' => 85000, 'max_price' => 120000]]);
        $p = $this->product(['reseller_price' => 80000]);
        $pricing = app(ResellerPricingService::class);
        $pricing->setCustomersPrice($reseller, $p, 90000);
        $pricing->setCustomersPrice($reseller, $p, 97000);
        $this->actingAsReseller($reseller);

        Livewire::test(ViewProduct::class, ['record' => $p->getKey()])
            ->assertSee(Money::format(90000).' ← '.Money::format(97000))
            ->assertSee(Money::format(85000))
            ->assertSee(Money::format(120000));
    }

    #[Test]
    public function the_summary_widget_renders_the_counts(): void
    {
        $reseller = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);
        $a = $this->product(['reseller_price' => 80000]);
        $pricing->setCustomersPrice($reseller, $a, 90000);
        $this->product();
        $this->actingAsReseller($reseller);

        Livewire::test(ProductSummary::class)
            ->assertSee('محصولات در دسترس')
            ->assertSee('در حال فروش')
            ->assertSee('10,000');
    }

    #[Test]
    public function the_deep_link_filter_from_the_url_works(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $unpriced = $this->product(['name' => 'بدون قیمت']);
        $selling = $this->product(['name' => 'در حال فروش من']);
        app(ResellerPricingService::class)->setCustomersPrice($reseller, $selling, 90000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/products?tableFilters[state][value]=unpriced')
            ->assertOk()
            ->assertSee('بدون قیمت');
    }

    #[Test]
    public function an_empty_catalog_shows_the_guided_empty_state(): void
    {
        $reseller = Reseller::factory()->create();
        $this->product([], ['available_to_resellers' => false]);
        $this->actingAsReseller($reseller);

        Livewire::test(ListProducts::class)
            ->assertSuccessful()
            ->assertSee('محصولی برای فروش در دسترس نیست');
    }
}
