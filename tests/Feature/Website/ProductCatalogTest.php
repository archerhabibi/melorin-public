<?php

namespace Tests\Feature\Website;

use App\Channels\ResellerBot\UpdateRouter as ResellerRouter;
use App\Channels\TelegramBot\UpdateRouter as MainRouter;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerBotSetting;
use App\Models\ResellerCategorySetting;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Core\Catalog\CatalogItem;
use App\Services\Core\Catalog\CatalogQuery;
use App\Services\Core\Catalog\CatalogText;
use App\Services\Core\Catalog\ProductCatalogService;
use App\Services\Core\Store\StoreContext;
use App\Services\Resellers\ResellerPricingService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

/**
 * B4.1 — Product Catalog. Contract: docs/canonical/CUSTOMER-CATALOG-CONTRACT.md
 */
class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array> */
    protected array $sent = [];

    private function core(): ProductCatalogService
    {
        return app(ProductCatalogService::class);
    }

    private function category(string $name = 'سبد', array $attrs = []): Category
    {
        return Category::factory()->create(array_merge(['name' => $name], $attrs));
    }

    private function product(Category $category, array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'category_id' => $category->id, 'name' => 'تعرفه '.random_int(1, 99999), 'main_price' => 100000,
            'traffic_gb' => 30, 'duration_days' => 30, 'status' => 'active',
        ], $attrs));
    }

    private function sell(Reseller $reseller, Product $product, int $price, bool $enabled = true): void
    {
        ResellerProductPrice::create(['reseller_id' => $reseller->id, 'product_id' => $product->id, 'customers_price' => $price, 'is_enabled' => $enabled]);
    }

    private function names($catalog): array
    {
        return $catalog->items->map(fn (CatalogItem $i) => $i->product->name)->all();
    }

    // ───────────────────────── Core: دیده‌شدن ─────────────────────────

    #[Test]
    public function the_main_catalog_shows_only_active_products_of_active_categories(): void
    {
        $ok = $this->category('فعال');
        $off = $this->category('بسته', ['status' => 'inactive']);
        $empty = $this->category('خالی');
        $this->product($ok, ['name' => 'نمایش']);
        $this->product($ok, ['name' => 'غیرفعال', 'status' => 'inactive']);
        $this->product($off, ['name' => 'سبد بسته']);

        $catalog = $this->core()->catalog(StoreContext::main());

        $this->assertSame(['نمایش'], $this->names($catalog));
        $this->assertSame([$ok->id], $catalog->categories->map(fn ($g) => $g->category->id)->all());
        $this->assertNotContains($empty->id, array_column($catalog->options, 'id'));
        $this->assertSame(1, $catalog->totalVisible);
        $this->assertFalse($catalog->isEmpty());
    }

    #[Test]
    public function an_empty_store_reports_empty_not_no_matches(): void
    {
        $catalog = $this->core()->catalog(StoreContext::main(), CatalogQuery::fromInput(['q' => 'x']));

        $this->assertTrue($catalog->isEmpty());
        $this->assertFalse($catalog->hasNoMatches());
    }

    #[Test]
    public function the_displayed_price_is_main_price_in_main_and_customers_price_in_a_reseller_store_never_reseller_price(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->product($this->category(), ['main_price' => 100000, 'reseller_price' => 70000]);
        $this->sell($reseller, $product, 130000);

        $this->assertSame(100000, $this->core()->find($product->id, StoreContext::main())->price);
        $this->assertSame(130000, $this->core()->find($product->id, StoreContext::reseller($reseller))->price);
        $this->assertSame([130000], $this->core()->catalog(StoreContext::reseller($reseller))->items->map->price->all());
    }

    #[Test]
    public function a_reseller_store_shows_only_what_that_reseller_opted_into_and_never_another_resellers_prices(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        $cat = $this->category();
        $p1 = $this->product($cat, ['name' => 'مشترک']);
        $p2 = $this->product($cat, ['name' => 'فقط B']);
        $this->product($cat, ['name' => 'هیچ‌کس']);
        $this->sell($a, $p1, 111000);
        $this->sell($b, $p1, 222000);
        $this->sell($b, $p2, 333000);

        $this->assertSame(['مشترک'], $this->names($this->core()->catalog(StoreContext::reseller($a))));
        $this->assertSame(111000, $this->core()->catalog(StoreContext::reseller($a))->items->first()->price);
        $this->assertSame(222000, $this->core()->catalog(StoreContext::reseller($b))->items->first()->price);
        $this->assertNull($this->core()->find($p2->id, StoreContext::reseller($a)));
    }

    #[Test]
    public function an_inactive_reseller_has_an_empty_catalog_and_nothing_is_found(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'inactive']);
        $product = $this->product($this->category());
        $this->sell($reseller, $product, 100);

        $this->assertTrue($this->core()->catalog(StoreContext::reseller($reseller))->isEmpty());
        $this->assertNull($this->core()->find($product->id, StoreContext::reseller($reseller)));
    }

    #[Test]
    public function the_list_and_the_single_lookup_always_agree_with_the_purchase_sellability_guard(): void
    {
        $reseller = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);

        $good = $this->product($this->category('خوب'));
        $this->sell($reseller, $good, 100);

        $inactiveProduct = $this->product($this->category('الف'), ['status' => 'inactive']);
        $this->sell($reseller, $inactiveProduct, 100);

        $closedCategory = $this->product($this->category('ب', ['status' => 'inactive']));
        $this->sell($reseller, $closedCategory, 100);

        $notForResellers = $this->product($this->category('پ', ['available_to_resellers' => false]));
        $this->sell($reseller, $notForResellers, 100);

        $resellerClosed = $this->product($closed = $this->category('ت'));
        $this->sell($reseller, $resellerClosed, 100);
        ResellerCategorySetting::create(['reseller_id' => $reseller->id, 'category_id' => $closed->id, 'is_enabled' => false]);

        $priceOff = $this->product($this->category('ث'));
        $this->sell($reseller, $priceOff, 100, enabled: false);

        $noPrice = $this->product($this->category('ج'));

        $store = StoreContext::reseller($reseller);
        $listed = $this->core()->catalog($store)->items->map(fn (CatalogItem $i) => $i->id())->all();

        foreach (Product::query()->get() as $p) {
            $sellable = $pricing->isSellable($reseller, $p->fresh('category'));
            $this->assertSame($sellable, in_array($p->id, $listed, true), "list≠guard برای «{$p->category->name}»");
            $this->assertSame($sellable, $this->core()->find($p->id, $store) !== null, "find≠guard برای «{$p->category->name}»");
        }

        $this->assertSame([$good->id], $listed);
        $this->assertNotNull($noPrice);
    }

    #[Test]
    public function building_the_reseller_catalog_uses_a_constant_number_of_queries(): void
    {
        $reseller = Reseller::factory()->create();
        $count = function (int $products) use ($reseller): int {
            for ($i = 0; $i < $products; $i++) {
                $p = $this->product($this->category('سبد '.$i.'-'.$products));
                $this->sell($reseller, $p, 1000 + $i);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->core()->catalog(StoreContext::reseller($reseller));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $count(2);
        $large = $count(15);

        $this->assertSame($small, $large, 'تعداد Query با تعداد تعرفه رشد نباید بکند (N+1)');
    }

    #[Test]
    public function reading_the_catalog_writes_nothing(): void
    {
        $reseller = Reseller::factory()->create();
        $product = $this->product($this->category());
        $this->sell($reseller, $product, 100);
        $before = [Order::count(), Account::count(), CustomerAccount::count(), AuditLog::count()];

        $this->core()->catalog(StoreContext::main());
        $this->core()->catalog(StoreContext::reseller($reseller), CatalogQuery::fromInput(['q' => 'تعرفه', 'sort' => 'price_asc']));
        $this->core()->find($product->id, StoreContext::main());

        $this->assertSame($before, [Order::count(), Account::count(), CustomerAccount::count(), AuditLog::count()]);
    }

    // ───────────────────────── Core: ظرفیت و ویژگی‌ها ─────────────────────────

    #[Test]
    public function capacity_state_is_derived_from_sale_limit_and_units_sold(): void
    {
        $cat = $this->category();
        $unlimited = $this->product($cat, ['sale_limit' => null]);
        $plenty = $this->product($cat, ['sale_limit' => 100, 'units_sold' => 10]);
        $low = $this->product($cat, ['sale_limit' => 10, 'units_sold' => 7]);
        $out = $this->product($cat, ['sale_limit' => 10, 'units_sold' => 10]);
        $over = $this->product($cat, ['sale_limit' => 10, 'units_sold' => 12]);

        $item = fn (Product $p) => $this->core()->find($p->id, StoreContext::main());

        $this->assertNull($item($unlimited)->remaining());
        $this->assertFalse($item($unlimited)->isSoldOut() || $item($unlimited)->isLowStock());
        $this->assertSame(90, $item($plenty)->remaining());
        $this->assertFalse($item($plenty)->isLowStock());
        $this->assertTrue($item($low)->isLowStock());
        $this->assertSame(3, $item($low)->remaining());
        $this->assertTrue($item($out)->isSoldOut());
        $this->assertSame(0, $item($over)->remaining());
        $this->assertTrue($item($over)->isSoldOut());
        $this->assertFalse($item($out)->isLowStock());
    }

    #[Test]
    public function traffic_and_duration_labels_follow_the_account_unlimited_rule(): void
    {
        $cat = $this->category();
        $item = fn (array $a) => $this->core()->find($this->product($cat, $a)->id, StoreContext::main());

        $this->assertSame('نامحدود', $item(['traffic_gb' => null])->trafficLabel());
        $this->assertSame('نامحدود', $item(['traffic_gb' => 0])->trafficLabel());
        $this->assertSame('30 گیگابایت', $item(['traffic_gb' => 30])->trafficLabel());
        $this->assertSame('1.5 گیگابایت', $item(['traffic_gb' => 1.5])->trafficLabel());
        $this->assertSame('45 روز', $item(['duration_days' => 45])->durationLabel());
    }

    #[Test]
    public function a_sold_out_product_is_still_found_but_flagged_and_alternatives_skip_sold_out_items(): void
    {
        $cat = $this->category();
        $out = $this->product($cat, ['name' => 'تمام', 'main_price' => 100, 'sale_limit' => 1, 'units_sold' => 1]);
        $near = $this->product($cat, ['name' => 'نزدیک', 'main_price' => 110]);
        $far = $this->product($cat, ['name' => 'دور', 'main_price' => 900]);
        $this->product($cat, ['name' => 'تمام۲', 'main_price' => 101, 'sale_limit' => 1, 'units_sold' => 1]);
        $other = $this->product($this->category('دیگر'), ['main_price' => 100]);

        $item = $this->core()->find($out->id, StoreContext::main());
        $alts = $this->core()->alternatives($item, StoreContext::main(), 2);

        $this->assertTrue($item->isSoldOut());
        $this->assertSame([$near->id, $far->id], $alts->map->id()->all());
        $this->assertNotContains($other->id, $alts->map->id()->all());
    }

    // ───────────────────────── Core: جست‌وجو/فیلتر/مرتب‌سازی ─────────────────────────

    #[Test]
    public function search_ignores_persian_arabic_digit_and_letter_variants_and_matches_all_words(): void
    {
        $cat = $this->category('اینترنت ویژه');
        $this->product($cat, ['name' => 'پلن ۳۰ روزه']);
        $this->product($cat, ['name' => 'پلن 90 روزه']);
        $this->product($cat, ['name' => 'پلن يک ماهه']);

        $find = fn (string $q) => $this->names($this->core()->catalog(StoreContext::main(), CatalogQuery::fromInput(['q' => $q])));

        $this->assertSame(['پلن ۳۰ روزه'], $find('30'));
        $this->assertSame(['پلن ۳۰ روزه'], $find('۳۰ روزه'));
        $this->assertSame(['پلن 90 روزه'], $find('٩٠'));
        $this->assertSame(['پلن يک ماهه'], $find('یک'));
        $this->assertSame(['پلن ۳۰ روزه'], $find('روزه 30'));
        $this->assertCount(3, $find('اینترنت'));
        $this->assertSame([], $find('30 90'));
        $this->assertSame([], $find('inexistent'));
    }

    #[Test]
    public function the_text_helpers_are_pure_and_safe(): void
    {
        $this->assertSame('علی رضا', CatalogText::clean("  علی \t\u{202E} رضا\u{200B}", 60));
        $this->assertSame(5, mb_strlen(CatalogText::clean(str_repeat('ا', 100), 5)));
        $this->assertSame('کی 12', CatalogText::fold('كي ١٢'));
        $this->assertTrue(CatalogText::matches('پلن ۳۰ روزه', 'روزه ۳۰'));
        $this->assertFalse(CatalogText::matches('پلن', 'پلن بیشتر'));
    }

    #[Test]
    public function like_wildcards_and_sql_in_the_search_are_plain_text(): void
    {
        $cat = $this->category();
        $this->product($cat, ['name' => 'عادی']);
        $this->product($cat, ['name' => '100% ویژه']);

        $find = fn (string $q) => $this->names($this->core()->catalog(StoreContext::main(), CatalogQuery::fromInput(['q' => $q])));

        $this->assertSame(['100% ویژه'], $find('%'));
        $this->assertSame([], $find('_'));
        $this->assertSame([], $find("' OR 1=1 --"));
        $this->assertSame(2, Product::count());
    }

    #[Test]
    public function the_query_sanitizes_every_kind_of_junk_without_throwing(): void
    {
        foreach ([
            ['q' => ['a'], 'category' => ['1'], 'sort' => ['x'], 'available' => ['1']],
            ['q' => str_repeat('ب', 500), 'category' => 'abc', 'sort' => 'DROP', 'available' => 'maybe'],
            ['q' => "   \u{200B}  ", 'category' => '-1', 'sort' => '', 'available' => ''],
            ['category' => '99999999999999999999999', 'sort' => null],
            ['category' => '0'],
        ] as $input) {
            $q = CatalogQuery::fromInput($input);
            $this->assertLessThanOrEqual(CatalogQuery::SEARCH_MAX, mb_strlen((string) $q->search));
            $this->assertNull($q->categoryId);
            $this->assertSame(CatalogQuery::SORT_DEFAULT, $q->sort);
            $this->assertFalse($q->availableOnly);
        }

        $q = CatalogQuery::fromInput(['q' => '  vip ', 'category' => '7', 'sort' => 'price_desc', 'available' => 'on']);
        $this->assertSame(['q' => 'vip', 'category' => 7, 'sort' => 'price_desc', 'available' => '1'], $q->toParams());
        $this->assertTrue($q->isFiltered());
        $this->assertSame([], (new CatalogQuery)->toParams());
        $this->assertFalse((new CatalogQuery(sort: 'price_asc'))->isFiltered());
    }

    #[Test]
    public function filtering_by_category_and_availability_keeps_the_filter_options_complete(): void
    {
        $a = $this->category('A');
        $b = $this->category('B');
        $this->product($a, ['name' => 'a1']);
        $this->product($a, ['name' => 'a2', 'sale_limit' => 1, 'units_sold' => 1]);
        $this->product($b, ['name' => 'b1']);

        $byCat = $this->core()->catalog(StoreContext::main(), new CatalogQuery(categoryId: $a->id));
        $this->assertEqualsCanonicalizing(['a1', 'a2'], $this->names($byCat));
        $this->assertSame(
            [['id' => $a->id, 'name' => 'A', 'count' => 2], ['id' => $b->id, 'name' => 'B', 'count' => 1]],
            $byCat->options,
            'منوی فیلتر با انتخاب سبد خالی نمی‌شود'
        );

        $avail = $this->core()->catalog(StoreContext::main(), new CatalogQuery(availableOnly: true));
        $this->assertEqualsCanonicalizing(['a1', 'b1'], $this->names($avail));
        $this->assertSame(3, $avail->totalVisible);
        $this->assertSame(2, $avail->totalMatched);

        $none = $this->core()->catalog(StoreContext::main(), new CatalogQuery(categoryId: 999999));
        $this->assertTrue($none->hasNoMatches());
    }

    #[Test]
    public function sorting_is_global_stable_puts_sold_out_last_and_treats_unlimited_traffic_as_the_largest(): void
    {
        $a = $this->category('A');
        $b = $this->category('B');
        $this->product($a, ['name' => 'ارزان-کوتاه', 'main_price' => 100, 'duration_days' => 30, 'traffic_gb' => 10]);
        $this->product($b, ['name' => 'گران-بلند', 'main_price' => 900, 'duration_days' => 90, 'traffic_gb' => 100]);
        $this->product($a, ['name' => 'متوسط-نامحدود', 'main_price' => 500, 'duration_days' => 60, 'traffic_gb' => null]);
        $this->product($b, ['name' => 'تمام‌شده', 'main_price' => 1, 'duration_days' => 365, 'traffic_gb' => 999, 'sale_limit' => 1, 'units_sold' => 1]);

        $sorted = fn (string $sort) => $this->names($this->core()->catalog(StoreContext::main(), new CatalogQuery(sort: $sort)));

        $this->assertSame(['ارزان-کوتاه', 'متوسط-نامحدود', 'گران-بلند', 'تمام‌شده'], $sorted('price_asc'));
        $this->assertSame(['گران-بلند', 'متوسط-نامحدود', 'ارزان-کوتاه', 'تمام‌شده'], $sorted('price_desc'));
        $this->assertSame(['گران-بلند', 'متوسط-نامحدود', 'ارزان-کوتاه', 'تمام‌شده'], $sorted('duration'));
        $this->assertSame(['متوسط-نامحدود', 'گران-بلند', 'ارزان-کوتاه', 'تمام‌شده'], $sorted('traffic'));

        $default = $this->core()->catalog(StoreContext::main());
        $this->assertTrue($default->isGrouped());
        $this->assertSame(['ارزان-کوتاه', 'متوسط-نامحدود', 'گران-بلند', 'تمام‌شده'], $this->names($default));
        $this->assertFalse($this->core()->catalog(StoreContext::main(), new CatalogQuery(sort: 'price_asc'))->isGrouped());
    }

    // ───────────────────────── Website ─────────────────────────

    #[Test]
    public function the_home_page_groups_by_category_and_shows_search_filters_and_badges(): void
    {
        $cat = $this->category('اینترنت');
        $this->product($cat, ['name' => 'عادی']);
        $this->product($cat, ['name' => 'تمام', 'sale_limit' => 1, 'units_sold' => 1]);
        $this->product($cat, ['name' => 'کم', 'sale_limit' => 10, 'units_sold' => 8]);

        $this->get(route('website.home'))->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('name="q"', false)
            ->assertSee('name="category"', false)
            ->assertSee('name="sort"', false)
            ->assertSee('name="available"', false)
            ->assertSee('اینترنت (3)')
            ->assertSee('ظرفیت تکمیل')
            ->assertSee('2 عدد باقی مانده')
            ->assertDontSee('noindex', false)
            ->assertDontSee('پاک‌کردن فیلترها');
    }

    #[Test]
    public function a_filtered_page_is_noindex_shows_the_count_and_a_clear_link(): void
    {
        $cat = $this->category('اینترنت');
        $this->product($cat, ['name' => 'طلایی']);
        $this->product($cat, ['name' => 'نقره‌ای']);

        $this->get(route('website.home', ['q' => 'طلایی']))->assertOk()
            ->assertSee('noindex,follow', false)
            ->assertSee('1 تعرفه از 2')
            ->assertSee('پاک‌کردن فیلترها')
            ->assertSee('طلایی')
            ->assertDontSee('نقره‌ای');

        $this->get(route('website.home', ['sort' => 'price_desc']))->assertOk()->assertSee('noindex,follow', false);
    }

    #[Test]
    public function no_matches_shows_an_empty_state_and_junk_query_values_never_error(): void
    {
        $this->product($this->category(), ['name' => 'تنها']);

        $this->get(route('website.home', ['q' => 'ناموجود']))->assertOk()->assertSee('تعرفه‌ای با این مشخصات پیدا نشد');

        foreach (['?q[]=x&category[]=1&sort[]=a&available[]=1', '?category=abc&sort=evil&available=zzz', '?q='.str_repeat('%E2%80%AE', 50), '?category=99999999999999999999999'] as $qs) {
            $this->get('/'.$qs)->assertOk();
        }
    }

    #[Test]
    public function a_search_term_is_escaped_in_the_page(): void
    {
        $this->product($this->category(), ['name' => 'x']);

        $html = $this->get(route('website.home', ['q' => '"><script>alert(1)</script>']))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function the_product_page_shows_labels_and_hides_buying_when_sold_out_offering_alternatives(): void
    {
        $cat = $this->category('اینترنت');
        $out = $this->product($cat, ['name' => 'تمام‌شده', 'main_price' => 100000, 'traffic_gb' => null, 'sale_limit' => 2, 'units_sold' => 2]);
        $this->product($cat, ['name' => 'جایگزین‌خوب', 'main_price' => 110000]);

        $this->get(route('website.products.show', $out))->assertOk()
            ->assertSee('ظرفیت تکمیل')
            ->assertSee('ظرفیت فروش این تعرفه تکمیل شده است.')
            ->assertSee('نامحدود')
            ->assertSee('تعرفه‌های جایگزین')
            ->assertSee('جایگزین‌خوب')
            ->assertDontSee('برای خرید وارد شوید')
            ->assertDontSee(route('website.checkout.show', $out), false)
            ->assertDontSee(route('website.guest-checkout.show', $out), false);

        $ok = $this->product($cat, ['name' => 'موجود']);
        $this->get(route('website.products.show', $ok))->assertOk()->assertSee('برای خرید وارد شوید')->assertDontSee('تعرفه‌های جایگزین');
    }

    #[Test]
    public function a_product_of_a_closed_category_is_not_visible_on_the_main_site_anymore(): void
    {
        $closed = $this->category('بسته', ['status' => 'inactive']);
        $p = $this->product($closed);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->get(route('website.products.show', $p))->assertNotFound();
        $this->actingAs($user)->get(route('website.checkout.show', $p))->assertNotFound();
    }

    #[Test]
    public function the_reseller_store_pages_are_scoped_to_that_reseller_with_its_own_prices_and_links(): void
    {
        $a = Reseller::factory()->create();
        $b = Reseller::factory()->create();
        $cat = $this->category('اینترنت');
        $p = $this->product($cat, ['name' => 'مشترک', 'main_price' => 100000, 'reseller_price' => 70000]);
        $hidden = $this->product($cat, ['name' => 'مخفی‌برای‌A']);
        $this->sell($a, $p, 123000);
        $this->sell($b, $p, 456000);
        $this->sell($b, $hidden, 999000);

        $this->get(route('website.store.home', $a->slug))->assertOk()
            ->assertSee('مشترک')->assertDontSee('مخفی‌برای‌A')
            ->assertSee(route('website.store.products.show', ['slug' => $a->slug, 'product' => $p->id]), false)
            ->assertSee(route('website.store.home', $a->slug), false);
        $this->get(route('website.store.products.show', ['slug' => $a->slug, 'product' => $hidden->id]))->assertNotFound();
        $this->get(route('website.store.products.show', ['slug' => $a->slug, 'product' => $p->id]))->assertOk()
            ->assertSee(number_format(123000), false) // قیمت A، نه B یا main یا reseller_price
            ->assertDontSee(number_format(456000), false)
            ->assertDontSee(number_format(70000), false);
    }

    // ───────────────────────── Telegram: هماهنگی با Core ─────────────────────────

    private function fakeBot(): void
    {
        $telegram = Mockery::mock(Api::class);
        $telegram->shouldReceive('sendMessage')->zeroOrMoreTimes()->andReturnUsing(function (array $params) {
            $this->sent[] = $params;

            return new Message(['message_id' => 1, 'date' => time(), 'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'], 'text' => 'x']);
        });
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(new \Telegram\Bot\Objects\User(['id' => 1, 'is_bot' => true, 'first_name' => 'B', 'username' => 'b_bot']));
        $this->app->instance(Api::class, $telegram);
    }

    private function cb(string $data, int $chat): Update
    {
        return new Update(['update_id' => random_int(1, PHP_INT_MAX), 'callback_query' => [
            'id' => '1', 'data' => $data, 'from' => ['id' => $chat, 'is_bot' => false, 'first_name' => 'T'],
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'private']],
        ]]);
    }

    private function txt(string $text, int $chat): Update
    {
        return new Update(['update_id' => random_int(1, PHP_INT_MAX), 'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chat, 'type' => 'private'], 'text' => $text]]);
    }

    private function lastText(): string
    {
        return (string) (end($this->sent)['text'] ?? '');
    }

    private function lastMarkup(): string
    {
        return (string) (end($this->sent)['reply_markup'] ?? '');
    }

    #[Test]
    public function the_main_bot_lists_the_same_categories_and_products_as_the_website_with_sold_out_marked(): void
    {
        $this->fakeBot();
        $user = User::factory()->create(['telegram_id' => 7001]);
        $shown = $this->category('نمایش');
        $this->category('خالی');
        $this->category('بسته', ['status' => 'inactive']);
        $ok = $this->product($shown, ['name' => 'موجود', 'main_price' => 5000]);
        $out = $this->product($shown, ['name' => 'تمام', 'sale_limit' => 1, 'units_sold' => 1]);

        app(MainRouter::class)->handle($this->txt('🛒 خرید اکانت', 7001), $user, 7001);
        $markup = json_decode($this->lastMarkup(), true);
        $this->assertSame(['نمایش'], collect($markup['inline_keyboard'])->flatten(1)->pluck('text')->all(), 'سبد خالی/بسته نباید بیاید');

        app(MainRouter::class)->handle($this->cb("buy:category:{$shown->id}", 7001), $user, 7001);
        $buttons = collect(json_decode($this->lastMarkup(), true)['inline_keyboard'])->flatten(1);
        $this->assertStringContainsString('موجود', $buttons[0]['text']);
        $this->assertStringContainsString('⛔', $buttons[1]['text']);
        $this->assertStringContainsString('ظرفیت تکمیل', $buttons[1]['text']);
        $this->assertSame("buy:product:{$ok->id}", $buttons[0]['callback_data']);

        // سبد ناموجود/بسته: پیام، نه خطا
        app(MainRouter::class)->handle($this->cb('buy:category:999999', 7001), $user, 7001);
        $this->assertStringContainsString('تعرفه‌ی فعالی', $this->lastText());

        // کلیک روی ظرفیت‌تکمیل ⇒ پیام، بدون سفارش
        app(MainRouter::class)->handle($this->cb("buy:product:{$out->id}", 7001), $user, 7001);
        $this->assertStringContainsString('ظرفیت فروش این تعرفه تکمیل شده است', $this->lastText());
        $this->assertSame(0, Order::count());

        // دکمه‌ی قدیمیِ محصولی که مخفی شده
        $ok->update(['status' => 'inactive']);
        app(MainRouter::class)->handle($this->cb("buy:product:{$ok->id}", 7001), $user, 7001);
        $this->assertStringContainsString('دیگر در دسترس نیست', $this->lastText());
        $this->assertSame(0, Order::count());
    }

    #[Test]
    public function the_reseller_bot_uses_the_same_core_catalog_with_customers_prices(): void
    {
        $this->fakeBot();
        $reseller = Reseller::factory()->create();
        // ردیف تنظیمات ربات باید از قبل ساخته شده باشد؛ `forReseller()` با firstOrCreate مقدار پیش‌فرض DB را روی مدلِ
        // تازه‌ساخته برنمی‌گرداند (bot_enabled=null) و اولین پیامِ یک نماینده‌ی نو «غیرفعال» حساب می‌شود (جدا از B4.1).
        ResellerBotSetting::create(['reseller_id' => $reseller->id, 'bot_enabled' => true]);
        $user = User::factory()->create(['telegram_id' => 7002]);
        $cat = $this->category('نماینده');
        $other = $this->category('ندارد');
        $p = $this->product($cat, ['name' => 'قابل‌فروش', 'main_price' => 1000, 'reseller_price' => 800]);
        $out = $this->product($cat, ['name' => 'تمام', 'sale_limit' => 1, 'units_sold' => 1]);
        $this->product($other, ['name' => 'فعال‌نشده']);
        $this->sell($reseller, $p, 1500);
        $this->sell($reseller, $out, 1600);

        $router = app(ResellerRouter::class);
        $router->handle($reseller, $this->txt('🛒 خرید اکانت', 7002), $user, 7002);
        $this->assertSame(['نماینده'], collect(json_decode($this->lastMarkup(), true)['inline_keyboard'])->flatten(1)->pluck('text')->all());

        $router->handle($reseller, $this->cb("rbuy:category:{$cat->id}", 7002), $user, 7002);
        $buttons = collect(json_decode($this->lastMarkup(), true)['inline_keyboard'])->flatten(1);
        $this->assertStringContainsString(Money::format(1500), $buttons[0]['text']);
        $this->assertStringNotContainsString(Money::format(800), $buttons[0]['text']);
        $this->assertStringContainsString('ظرفیت تکمیل', $buttons[1]['text']);

        $router->handle($reseller, $this->cb("rbuy:product:{$out->id}", 7002), $user, 7002);
        $this->assertStringContainsString('ظرفیت فروش این تعرفه تکمیل شده است', $this->lastText());
        $this->assertSame(0, Order::count());

        $router->handle($reseller, $this->cb("rbuy:category:{$other->id}", 7002), $user, 7002);
        $this->assertStringContainsString('محصولی تنظیم نشده', $this->lastText());
    }
}
