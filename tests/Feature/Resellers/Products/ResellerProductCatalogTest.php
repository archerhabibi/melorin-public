<?php

namespace Tests\Feature\Resellers\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerCategorySetting;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Resellers\Products\PriceBounds;
use App\Services\Resellers\Products\ProductMargin;
use App\Services\Resellers\Products\ProductSaleState;
use App\Services\Resellers\Products\ResellerProductCatalog;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B5.3 — کاتالوگ محصولات نماینده (Core، فقط‌خواندنی): دسترسی، وضعیت فروش، سود، خلاصه، پرونده و سابقه،
 * و هم‌ارزی تعریف «قابل فروش» با ResellerPricingService (تنها تعریف رسمی).
 */
class ResellerProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ResellerProductCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = app(ResellerProductCatalog::class);
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

    private function price(Reseller $reseller, Product $product, int $price, bool $enabled = true): ResellerProductPrice
    {
        return ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $price,
            'is_enabled' => $enabled,
        ]);
    }

    private function sale(Reseller $reseller, Product $product, int $price, int $supply, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $supply,
            'customers_price' => $price,
            'status' => $status,
        ]);
    }

    private function ids(Reseller $reseller): array
    {
        return $this->catalog->query($reseller)->pluck('products.id')->all();
    }

    // ───────────── دسترسی ─────────────

    #[Test]
    public function only_active_products_in_categories_open_to_resellers_are_listed(): void
    {
        $reseller = Reseller::factory()->create();
        $visible = $this->product();
        $inactiveProduct = $this->product(['status' => 'inactive']);
        $closedByCore = $this->product([], ['available_to_resellers' => false]);
        $inactiveCategory = $this->product([], ['status' => 'inactive']);

        $ids = $this->ids($reseller);

        $this->assertContains($visible->id, $ids);
        $this->assertContains($inactiveCategory->id, $ids, 'سبد غیرفعال دیده می‌شود (با وضعیت و علت)');
        $this->assertNotContains($inactiveProduct->id, $ids);
        $this->assertNotContains($closedByCore->id, $ids, 'سبد بسته‌شده توسط Core اصلاً دیده نمی‌شود');
    }

    // ───────────── وضعیت فروش ─────────────

    #[Test]
    public function each_state_is_resolved_with_the_documented_priority(): void
    {
        $reseller = Reseller::factory()->create();

        $selling = $this->product();
        $this->price($reseller, $selling, 90000);

        $paused = $this->product();
        $this->price($reseller, $paused, 90000, enabled: false);

        $unpriced = $this->product();

        $off = $this->product();
        $this->price($reseller, $off, 90000);
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $off->category_id, 'is_enabled' => false]);

        $inactive = $this->product([], ['status' => 'inactive']);
        $this->price($reseller, $inactive, 90000);
        // اولویت: سبد غیرفعال در Core بر خاموشی من برتری دارد
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $inactive->category_id, 'is_enabled' => false]);

        $state = fn (Product $p) => $this->catalog->stateFor($reseller, $p);

        $this->assertSame(ProductSaleState::Selling, $state($selling));
        $this->assertSame(ProductSaleState::Paused, $state($paused));
        $this->assertSame(ProductSaleState::Unpriced, $state($unpriced));
        $this->assertSame(ProductSaleState::CategoryOff, $state($off));
        $this->assertSame(ProductSaleState::CategoryInactive, $state($inactive));
    }

    #[Test]
    public function an_inactive_reseller_has_every_product_in_the_reseller_inactive_state(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'inactive']);
        $p = $this->product();
        $this->price($reseller, $p, 90000);

        $this->assertSame(ProductSaleState::ResellerInactive, $this->catalog->stateFor($reseller, $p));
        $this->assertSame([$p->id], $this->catalog->applyState($this->catalog->query($reseller), $reseller, ProductSaleState::ResellerInactive)->pluck('products.id')->all());
        $this->assertSame([], $this->catalog->applyState($this->catalog->query($reseller), $reseller, ProductSaleState::Selling)->pluck('products.id')->all());
    }

    #[Test]
    public function state_is_null_for_a_product_the_reseller_cannot_manage(): void
    {
        $reseller = Reseller::factory()->create();
        $closed = $this->product([], ['available_to_resellers' => false]);

        $this->assertNull($this->catalog->stateFor($reseller, $closed));
        $this->assertNull($this->catalog->profile($reseller, $closed));
    }

    /**
     * محافظ اصلی: «در حال فروش» در کاتالوگ ⇔ ResellerPricingService::isSellable، روی همه‌ی ترکیب‌ها؛ و تعریف SQL
     * (applyState) با تعریف PHP (stateOf) یکی است. اگر کسی یکی را عوض کند و دیگری را نه، این تست می‌شکند.
     */
    #[Test]
    public function the_catalog_state_agrees_with_the_official_sellability_for_every_combination(): void
    {
        $pricing = app(ResellerPricingService::class);
        $checked = 0;

        foreach (['active', 'inactive'] as $resellerStatus) {
            foreach (['active', 'inactive'] as $categoryStatus) {
                foreach ([null, true, false] as $categorySetting) {
                    foreach ([null, true, false] as $priceEnabled) {
                        $reseller = Reseller::factory()->create(['status' => $resellerStatus]);
                        $product = $this->product([], ['status' => $categoryStatus]);

                        if ($categorySetting !== null) {
                            ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $product->category_id, 'is_enabled' => $categorySetting]);
                        }
                        if ($priceEnabled !== null) {
                            $this->price($reseller, $product, 90000, $priceEnabled);
                        }

                        $row = $this->catalog->query($reseller)->whereKey($product->id)->firstOrFail();
                        $state = $this->catalog->stateOf($reseller, $row);

                        $this->assertSame(
                            $pricing->isSellable($reseller, $product->fresh()),
                            $state === ProductSaleState::Selling,
                            "ناسازگاری فروش: {$resellerStatus}/{$categoryStatus}/".json_encode($categorySetting).'/'.json_encode($priceEnabled)
                        );

                        foreach (ProductSaleState::cases() as $candidate) {
                            $matches = $this->catalog->applyState($this->catalog->query($reseller), $reseller, $candidate)->whereKey($product->id)->exists();
                            $this->assertSame($candidate === $state, $matches, "SQL≠PHP برای {$candidate->value} در وضعیت {$state->value}");
                        }

                        $checked++;
                    }
                }
            }
        }

        $this->assertSame(36, $checked);
    }

    // ───────────── ستون‌های محاسبه‌شده و جداسازی ─────────────

    #[Test]
    public function columns_use_reseller_price_with_main_price_fallback_and_this_resellers_price_only(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $withSupply = $this->product(['main_price' => 100000, 'reseller_price' => 80000]);
        $fallback = $this->product(['main_price' => 120000, 'reseller_price' => null]);
        $this->price($reseller, $withSupply, 95000);
        $this->price($other, $withSupply, 99999);
        $this->price($other, $fallback, 99999);

        $rows = $this->catalog->query($reseller)->get()->keyBy('id');

        $this->assertSame(80000, (int) $rows[$withSupply->id]->supply_price);
        $this->assertSame(95000, (int) $rows[$withSupply->id]->my_price);
        $this->assertSame(15000, (int) $rows[$withSupply->id]->profit);
        $this->assertSame(120000, (int) $rows[$fallback->id]->supply_price, 'بدون reseller_price به main_price برمی‌گردد');
        $this->assertNull($rows[$fallback->id]->my_price, 'قیمت نماینده‌ی دیگر نشت نمی‌کند');
        $this->assertNull($rows[$fallback->id]->profit);
    }

    #[Test]
    public function sales_count_only_counts_this_resellers_settled_orders_of_that_product(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $p = $this->product();
        $otherProduct = $this->product();

        $this->sale($reseller, $p, 90000, 80000, 'account_created');
        $this->sale($reseller, $p, 90000, 80000, 'paid');
        $this->sale($reseller, $p, 90000, 80000, 'pending');
        $this->sale($reseller, $p, 90000, 80000, 'provision_failed');
        $this->sale($other, $p, 90000, 80000);
        $this->sale($reseller, $otherProduct, 90000, 80000);

        $rows = $this->catalog->query($reseller)->get()->keyBy('id');

        $this->assertSame(2, (int) $rows[$p->id]->sales_count);
        $this->assertSame(1, (int) $rows[$otherProduct->id]->sales_count);
        $this->assertNotNull($rows[$p->id]->last_sold_at);
    }

    #[Test]
    public function the_query_count_does_not_depend_on_the_number_of_products(): void
    {
        $reseller = Reseller::factory()->create();
        $count = function () use ($reseller): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->catalog->query($reseller)->get();
            foreach ($rows as $row) {
                $this->catalog->stateOf($reseller, $row);
                $this->catalog->marginOf($row);
            }

            return count(DB::getQueryLog());
        };

        $this->price($reseller, $this->product(), 90000);
        $few = $count();

        foreach (range(1, 8) as $_) {
            $this->price($reseller, $this->product(), 91000);
        }

        $this->assertSame($few, $count());
    }

    // ───────────── خلاصه ─────────────

    #[Test]
    public function the_summary_counts_come_from_the_same_definition_as_the_filter(): void
    {
        $reseller = Reseller::factory()->create();

        $a = $this->product(['reseller_price' => 80000]);
        $this->price($reseller, $a, 90000);          // سود ۱۰٬۰۰۰
        $b = $this->product(['reseller_price' => 50000]);
        $this->price($reseller, $b, 50000);          // سود صفر
        $c = $this->product();
        $this->price($reseller, $c, 99999, enabled: false);
        $this->product();                            // بدون قیمت
        $off = $this->product();
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $off->category_id, 'is_enabled' => false]);
        $this->product([], ['available_to_resellers' => false]); // دیده نمی‌شود

        $s = $this->catalog->summary($reseller);

        $this->assertSame(5, $s->total);
        $this->assertSame(2, $s->selling);
        $this->assertSame(1, $s->paused);
        $this->assertSame(1, $s->unpriced);
        $this->assertSame(1, $s->blocked);
        $this->assertSame(5000, $s->averageProfit, 'میانگین (۱۰٬۰۰۰ و ۰) فقط روی محصولات در حال فروش');
        $this->assertSame(1, $s->zeroProfit);

        foreach ([[ProductSaleState::Selling, 2], [ProductSaleState::Paused, 1], [ProductSaleState::Unpriced, 1], [ProductSaleState::CategoryOff, 1]] as [$state, $n]) {
            $this->assertCount($n, $this->catalog->applyState($this->catalog->query($reseller), $reseller, $state)->get(), $state->value);
        }
    }

    #[Test]
    public function the_summary_of_an_empty_catalog_has_no_average(): void
    {
        $s = $this->catalog->summary(Reseller::factory()->create());

        $this->assertSame(0, $s->total);
        $this->assertNull($s->averageProfit);
        $this->assertSame(0, $s->zeroProfit);
    }

    // ───────────── پرونده و سابقه ─────────────

    #[Test]
    public function the_profile_reports_sales_profit_and_only_this_resellers_history_newest_first(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $p = $this->product(['reseller_price' => 80000]);
        $pricing = app(ResellerPricingService::class);

        $pricing->setCustomersPrice($reseller, $p, 90000);
        $pricing->setCustomersPrice($other, $p, 95000);
        $pricing->setCustomersPrice($reseller, $p, 92000);
        $pricing->disable($reseller, $p);

        $this->sale($reseller, $p, 90000, 80000);
        $this->sale($reseller, $p, 92000, 80000);
        $this->sale($other, $p, 95000, 80000);

        $profile = $this->catalog->profile($reseller, $p);

        $this->assertSame(ProductSaleState::Paused, $profile->state);
        $this->assertSame(92000, $profile->customersPrice, 'قیمت ذخیره‌شده حتی در حالت غیرفعال');
        $this->assertSame(2, $profile->salesCount);
        $this->assertSame(182000, $profile->salesRevenue);
        $this->assertSame(22000, $profile->salesProfit);
        $this->assertSame(12000, $profile->margin->profit);

        $this->assertCount(3, $profile->history, 'تغییر نماینده‌ی دیگر در سابقه نیست');
        $this->assertSame('product.disabled', $profile->history[0]['action']);
        $this->assertSame([90000, 92000], [$profile->history[1]['from'], $profile->history[1]['to']]);
        $this->assertNull($profile->history[2]['from']);
        $this->assertSame(90000, $profile->history[2]['to']);
    }

    #[Test]
    public function reading_never_writes_anything(): void
    {
        $reseller = Reseller::factory()->create();
        $p = $this->product();
        $this->price($reseller, $p, 90000);
        $before = [DB::table('reseller_product_prices')->count(), DB::table('audit_logs')->count(), DB::table('wallets')->count(), DB::table('orders')->count()];

        $this->catalog->query($reseller)->get();
        $this->catalog->summary($reseller);
        $this->catalog->profile($reseller, $p);
        $this->catalog->stateFor($reseller, $p);

        $this->assertSame($before, [DB::table('reseller_product_prices')->count(), DB::table('audit_logs')->count(), DB::table('wallets')->count(), DB::table('orders')->count()]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    // ───────────── حاشیه و محدوده‌ی قیمت ─────────────

    #[Test]
    public function margin_percent_uses_integer_rounding_on_the_supply_price(): void
    {
        $this->assertSame([10000, 13], [ProductMargin::of(90000, 80000)->profit, ProductMargin::of(90000, 80000)->percent]); // ۱۲٫۵ ⇒ ۱۳
        $this->assertSame(0, ProductMargin::of(80000, 80000)->percent);
        $this->assertSame(0, ProductMargin::of(80000, 80000)->profit);
        $this->assertNull(ProductMargin::of(1000, 0)->percent, 'مبنای صفر ⇒ بدون درصد');
        $this->assertSame(1000, ProductMargin::of(1000, 0)->profit);
        $this->assertSame(-13, ProductMargin::of(70000, 80000)->percent);
    }

    #[Test]
    public function price_bounds_combine_every_rule_and_the_supply_floor(): void
    {
        $reseller = Reseller::factory()->create(['min_sale_price_rule' => ['min_price' => 85000, 'max_price' => 120000, 'min_profit' => 8000, 'max_profit' => 30000]]);
        $p = $this->product(['reseller_price' => 80000]);

        $b = PriceBounds::for($reseller, $p);

        $this->assertSame(88000, $b->min(), 'بزرگ‌ترین کف: supply+min_profit');
        $this->assertSame(110000, $b->max(), 'کوچک‌ترین سقف: supply+max_profit');
        $this->assertFalse($b->isEmpty());
        $this->assertSame(PriceBounds::MSG_MIN_PROFIT, $b->violation(86000));
        $this->assertNull($b->violation(88000));
        $this->assertNull($b->violation(110000));
        $this->assertSame(PriceBounds::MSG_MAX_PROFIT, $b->violation(111000));
    }

    #[Test]
    public function without_rules_the_only_bound_is_the_supply_floor(): void
    {
        $b = PriceBounds::for(Reseller::factory()->create(), $this->product(['reseller_price' => 80000]));

        $this->assertSame(80000, $b->min());
        $this->assertNull($b->max());
        $this->assertSame(PriceBounds::MSG_BELOW_SUPPLY, $b->violation(79999));
        $this->assertNull($b->violation(80000));
    }

    #[Test]
    public function contradictory_rules_are_reported_as_an_empty_range(): void
    {
        $reseller = Reseller::factory()->create(['min_sale_price_rule' => ['min_price' => 150000, 'max_price' => 100000]]);

        $this->assertTrue(PriceBounds::for($reseller, $this->product())->isEmpty());
    }

    /** بازه‌ای که فرم نشان می‌دهد دقیقاً همان است که ثبت واقعی می‌پذیرد (مرز و بیرون مرز) */
    #[Test]
    public function what_the_bounds_allow_is_exactly_what_setting_a_price_accepts(): void
    {
        $pricing = app(ResellerPricingService::class);
        $rules = [
            null,
            ['min_price' => 85000],
            ['max_price' => 120000],
            ['min_profit' => 8000, 'max_profit' => 30000],
            ['min_price' => 85000, 'max_price' => 120000, 'min_profit' => 8000, 'max_profit' => 30000],
        ];

        foreach ($rules as $rule) {
            $reseller = Reseller::factory()->create(['min_sale_price_rule' => $rule]);
            $p = $this->product(['reseller_price' => 80000]);
            $bounds = $pricing->priceBounds($reseller, $p);

            foreach ([0, 79999, 80000, 84999, 85000, 87999, 88000, 100000, 110000, 110001, 120000, 120001, 500000] as $price) {
                $accepted = true;
                try {
                    $pricing->setCustomersPrice($reseller, $p, $price);
                } catch (InvalidArgumentException) {
                    $accepted = false;
                }

                $this->assertSame($bounds->allows($price), $accepted, 'rule='.json_encode($rule)." price={$price}");
                $this->assertSame($accepted, $price >= $bounds->min() && ($bounds->max() === null || $price <= $bounds->max()), 'min/max rule='.json_encode($rule)." price={$price}");
            }
        }
    }

    #[Test]
    public function re_enabling_requires_a_stored_price_that_is_still_allowed(): void
    {
        $pricing = app(ResellerPricingService::class);
        $reseller = Reseller::factory()->create();
        $p = $this->product(['reseller_price' => 80000]);

        try {
            $pricing->enable($reseller, $p);
            $this->fail('بدون قیمت ذخیره‌شده نباید فعال شود');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('قیمتی ثبت نشده', $e->getMessage());
        }

        $pricing->setCustomersPrice($reseller, $p, 90000);
        $pricing->disable($reseller, $p);
        $this->assertFalse($pricing->isSellable($reseller, $p));

        $pricing->enable($reseller, $p);
        $this->assertTrue($pricing->isSellable($reseller->fresh(), $p->fresh()));

        // ادمین سقف را پایین می‌آورد؛ قیمت قدیمی دیگر مجاز نیست و بی‌صدا فعال نمی‌شود
        $pricing->disable($reseller, $p);
        $reseller->update(['min_sale_price_rule' => ['max_price' => 85000]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('قیمت قبلی دیگر مجاز نیست');
        $pricing->enable($reseller->fresh(), $p);
    }
}
