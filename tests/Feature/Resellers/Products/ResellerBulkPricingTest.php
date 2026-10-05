<?php

namespace Tests\Feature\Resellers\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Services\Resellers\Products\MarkupMode;
use App\Services\Resellers\Products\PriceBounds;
use App\Services\Resellers\Products\ResellerBulkPricing;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B5.3 — قیمت‌گذاری و فعال/غیرفعال‌سازی گروهی. قاعده‌ی قیمت تکرار نشده (PriceBounds)، ردِ صریح با دلیل،
 * و هیچ محصولِ بیرون از دسترس نماینده با شناسه‌ی دست‌ساز تغییر نمی‌کند.
 */
class ResellerBulkPricingTest extends TestCase
{
    use RefreshDatabase;

    private ResellerBulkPricing $bulk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bulk = app(ResellerBulkPricing::class);
    }

    private function product(int $supply = 80000, array $category = []): Product
    {
        return Product::factory()->create([
            'category_id' => Category::factory()->create($category)->id,
            'main_price' => $supply + 20000,
            'reseller_price' => $supply,
        ]);
    }

    private function priceOf(Reseller $r, Product $p): ?int
    {
        return ResellerProductPrice::query()->where('reseller_id', $r->id)->where('product_id', $p->id)->value('customers_price');
    }

    private function enabledOf(Reseller $r, Product $p): ?bool
    {
        $v = ResellerProductPrice::query()->where('reseller_id', $r->id)->where('product_id', $p->id)->value('is_enabled');

        return $v === null ? null : (bool) $v;
    }

    private function audits(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    // ───────────── قیمت‌گذاری ─────────────

    #[Test]
    public function a_percent_markup_prices_from_the_supply_price_and_enables_each_product(): void
    {
        $r = Reseller::factory()->create();
        $a = $this->product(80000);
        $b = $this->product(50000);

        $result = $this->bulk->markup($r, [$a->id, $b->id], MarkupMode::Percent, 20);

        $this->assertSame([$a->id, $b->id], $result->applied);
        $this->assertSame(96000, $this->priceOf($r, $a));
        $this->assertSame(60000, $this->priceOf($r, $b));
        $this->assertTrue($this->enabledOf($r, $a));
        $this->assertTrue(app(ResellerPricingService::class)->isSellable($r, $a->fresh()));
    }

    #[Test]
    public function percent_rounds_half_up_to_the_unit(): void
    {
        $r = Reseller::factory()->create();
        $p = $this->product(1250); // ۱۲٫۵٪ از ۱۲۵۰ = ۱۵۶٫۲۵ ⇒ ۱۵۶

        $this->bulk->markup($r, [$p->id], MarkupMode::Percent, 25); // ۲۵٪ = ۳۱۲٫۵ ⇒ ۳۱۳
        $this->assertSame(1563, $this->priceOf($r, $p));
    }

    #[Test]
    public function a_fixed_markup_and_round_up_to_a_multiple(): void
    {
        $r = Reseller::factory()->create();
        $p = $this->product(80000);

        $this->bulk->markup($r, [$p->id], MarkupMode::Fixed, 12345, roundTo: 1000);

        $this->assertSame(93000, $this->priceOf($r, $p), '۹۲٬۳۴۵ ⇒ رو‌به‌بالا به هزار');
    }

    #[Test]
    public function a_zero_markup_sells_at_supply_price_which_is_allowed(): void
    {
        $r = Reseller::factory()->create();
        $p = $this->product(80000);

        $result = $this->bulk->markup($r, [$p->id], MarkupMode::Fixed, 0);

        $this->assertSame(1, $result->appliedCount());
        $this->assertSame(80000, $this->priceOf($r, $p));
    }

    #[Test]
    public function a_product_whose_computed_price_breaks_the_rules_is_skipped_with_the_reason_and_not_clamped(): void
    {
        $r = Reseller::factory()->create(['min_sale_price_rule' => ['max_price' => 100000]]);
        $ok = $this->product(80000);       // ۹۶٬۰۰۰ ✔
        $tooHigh = $this->product(90000);  // ۱۰۸٬۰۰۰ ✘

        $result = $this->bulk->markup($r, [$ok->id, $tooHigh->id], MarkupMode::Percent, 20);

        $this->assertSame([$ok->id], $result->applied);
        $this->assertSame([$tooHigh->id => PriceBounds::MSG_MAX_PRICE], $result->skipped);
        $this->assertNull($this->priceOf($r, $tooHigh), 'برش/گرد بی‌صدا ندارد؛ دست‌نخورده می‌ماند');
    }

    #[Test]
    public function rounding_up_that_breaks_the_cap_is_a_skip_not_a_silent_change(): void
    {
        $r = Reseller::factory()->create(['min_sale_price_rule' => ['max_price' => 96500]]);
        $p = $this->product(80000);

        $result = $this->bulk->markup($r, [$p->id], MarkupMode::Percent, 20, roundTo: 1000); // ۹۶٬۰۰۰ ⇒ ۹۶٬۰۰۰ ✔
        $this->assertSame(1, $result->appliedCount());

        $q = $this->product(80500);
        $result = $this->bulk->markup($r, [$q->id], MarkupMode::Percent, 20, roundTo: 1000); // ۹۶٬۶۰۰ ⇒ ۹۷٬۰۰۰ ✘
        $this->assertSame([$q->id => PriceBounds::MSG_MAX_PRICE], $result->skipped);
    }

    #[Test]
    public function invalid_input_is_rejected_before_anything_is_written(): void
    {
        $r = Reseller::factory()->create();
        $p = $this->product();

        foreach ([
            [MarkupMode::Percent, -1, 1],
            [MarkupMode::Percent, MarkupMode::MAX_PERCENT + 1, 1],
            [MarkupMode::Fixed, -5, 1],
            [MarkupMode::Fixed, 1000, 0],
        ] as [$mode, $value, $round]) {
            try {
                $this->bulk->markup($r, [$p->id], $mode, $value, $round);
                $this->fail('ورودی نامعتبر باید رد شود');
            } catch (InvalidArgumentException) {
                $this->assertNull($this->priceOf($r, $p));
            }
        }
    }

    // ───────────── دسترسی و جداسازی ─────────────

    #[Test]
    public function a_product_outside_the_resellers_reach_is_never_touched_even_with_a_crafted_id(): void
    {
        $r = Reseller::factory()->create();
        $closed = $this->product(80000, ['available_to_resellers' => false]);
        $inactive = Product::factory()->create(['category_id' => Category::factory()->create()->id, 'status' => 'inactive', 'reseller_price' => 80000]);
        $ghost = 999999;

        $result = $this->bulk->markup($r, [$closed->id, $inactive->id, $ghost], MarkupMode::Percent, 10);

        $this->assertSame([], $result->applied);
        $this->assertSame([$closed->id => ResellerBulkPricing::NOT_AVAILABLE, $inactive->id => ResellerBulkPricing::NOT_AVAILABLE, $ghost => ResellerBulkPricing::NOT_AVAILABLE], $result->skipped);
        $this->assertSame(0, ResellerProductPrice::query()->count());
    }

    #[Test]
    public function another_resellers_prices_are_never_touched(): void
    {
        $r = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $p = $this->product(80000);
        app(ResellerPricingService::class)->setCustomersPrice($other, $p, 99000);

        $this->bulk->markup($r, [$p->id], MarkupMode::Percent, 10);
        $this->bulk->disable($r, [$p->id]);

        $this->assertSame(99000, $this->priceOf($other, $p));
        $this->assertTrue($this->enabledOf($other, $p));
    }

    // ───────────── فعال/غیرفعال ─────────────

    #[Test]
    public function bulk_enable_skips_unpriced_already_active_and_no_longer_allowed_prices(): void
    {
        $r = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);

        $paused = $this->product(80000);
        $pricing->setCustomersPrice($r, $paused, 90000);
        $pricing->disable($r, $paused);

        $unpriced = $this->product();

        $active = $this->product();
        $pricing->setCustomersPrice($r, $active, 95000);

        $stale = $this->product(80000);
        $pricing->setCustomersPrice($r, $stale, 98000);
        $pricing->disable($r, $stale);
        // ادمین سقف را پایین می‌آورد: ۹۰٬۰۰۰ هنوز مجاز، ۹۸٬۰۰۰ دیگر نه
        $r->update(['min_sale_price_rule' => ['max_price' => 95000]]);

        $result = $this->bulk->enable($r->fresh(), [$paused->id, $unpriced->id, $active->id, $stale->id]);

        $this->assertSame([$paused->id], $result->applied);
        $this->assertStringContainsString('ثبت نشده', $result->skipped[$unpriced->id]);
        $this->assertStringContainsString('از قبل فعال', $result->skipped[$active->id]);
        $this->assertStringContainsString('قیمت قبلی دیگر مجاز نیست', $result->skipped[$stale->id]);
        $this->assertTrue($this->enabledOf($r, $paused));
        $this->assertFalse($this->enabledOf($r, $stale), 'قیمت نامعتبر بی‌صدا فعال نشد');
        $this->assertNull($this->priceOf($r, $unpriced), 'فعال‌سازی گروهی قیمت نمی‌سازد');
    }

    #[Test]
    public function bulk_disable_keeps_the_stored_price_and_skips_what_was_not_active(): void
    {
        $r = Reseller::factory()->create();
        $pricing = app(ResellerPricingService::class);
        $on = $this->product();
        $pricing->setCustomersPrice($r, $on, 95000);
        $never = $this->product();

        $result = $this->bulk->disable($r, [$on->id, $never->id]);

        $this->assertSame([$on->id], $result->applied);
        $this->assertSame([$never->id => 'فعال نبود.'], $result->skipped);
        $this->assertFalse($this->enabledOf($r, $on));
        $this->assertSame(95000, $this->priceOf($r, $on), 'قیمت برای فعال‌سازی دوباره می‌ماند');
        $this->assertNull($this->enabledOf($r, $never));
    }

    // ───────────── Audit ─────────────

    #[Test]
    public function every_applied_product_is_audited_like_a_single_change(): void
    {
        $r = Reseller::factory()->create();
        $a = $this->product();
        $b = $this->product();

        $this->bulk->markup($r, [$a->id, $b->id], MarkupMode::Percent, 10);
        $this->assertSame(2, $this->audits('product.price_changed'));

        $this->bulk->disable($r, [$a->id, $b->id]);
        $this->assertSame(2, $this->audits('product.disabled'));

        // اعمال‌نشده‌ها Audit ندارند
        $this->bulk->disable($r, [$a->id]);
        $this->assertSame(2, $this->audits('product.disabled'));
    }

    #[Test]
    public function duplicate_and_zero_ids_are_ignored_and_an_empty_selection_does_nothing(): void
    {
        $r = Reseller::factory()->create();
        $p = $this->product();

        $result = $this->bulk->markup($r, [$p->id, (string) $p->id, 0], MarkupMode::Percent, 10);
        $this->assertSame([$p->id], $result->applied);
        $this->assertSame(1, $this->audits('product.price_changed'));

        $empty = $this->bulk->enable($r, []);
        $this->assertSame(0, $empty->appliedCount() + $empty->skippedCount());
    }
}
