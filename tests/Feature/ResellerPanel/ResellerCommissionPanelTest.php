<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Resources\CommissionResource;
use App\Filament\Reseller\Resources\CommissionResource\Pages\ListCommissions;
use App\Filament\Reseller\Resources\CommissionResource\Widgets\CommissionSummary;
use App\Filament\Reseller\Resources\CommissionResource\Widgets\TopReferrers;
use App\Models\AffiliateSetting;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.4 — لایه‌ی نمایش مرکز کمیسیون نماینده. منطق در ResellerCommissionCenterTest تست شده؛
 * این‌جا: رندر واقعی، جداسازی، فیلتر/جست‌وجو/مرتب‌سازی، ویجت‌های وابسته به فیلتر جدول، صفحه‌ی جزئیات و فقط‌مشاهده‌بودن.
 */
class ResellerCommissionPanelTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    /**
     * Livewire (SupportPagination) روی Paginator مقدارهای static می‌گذارد (Path/Page Resolver و View پیش‌فرض) و فقط
     * هنگام destroy همان کامپوننت بازشان می‌گرداند. ویجت‌هایی که این‌جا مستقل Mount می‌شوند آن را برنمی‌گردانند و
     * Resolver به کامپوننتِ مرده‌ی همین تست وصل می‌ماند؛ نتیجه: تست‌های صفحه‌بندی سایت در ادامه‌ی اجرای کل مجموعه
     * لینک‌های اشتباه می‌ساختند (فقط در اجرای کامل دیده می‌شد، نه در اجرای تکی).
     */
    protected function tearDown(): void
    {
        PaginationState::resolveUsing($this->app);
        Paginator::defaultView('pagination::tailwind');
        Paginator::defaultSimpleView('pagination::simple-tailwind');

        parent::tearDown();
    }

    private function actingAsReseller(Reseller $reseller): User
    {
        $owner = $reseller->user;
        ResellerAdmin::query()->firstOrCreate(['reseller_id' => $reseller->id, 'user_id' => $owner->id], ['role' => 'owner']);

        $this->actingAs($owner, 'reseller');
        Filament::setTenant($reseller);

        return $owner;
    }

    private function order(Reseller $reseller, User $buyer, int $price = 100000, int $supply = 60000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $buyer->id,
            'customer_account_id' => $this->accountIn($buyer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $supply,
            'customers_price' => $price,
            'status' => $status,
        ]);
    }

    private function commission(Order $order, User $referrer, int $amount, string $type = Commission::TYPE_ONGOING): Commission
    {
        return Commission::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $order->user_id,
            'referred_customer_account_id' => $order->customer_account_id,
            'order_id' => $order->id,
            'type' => $type,
            'commission_rate' => $type === Commission::TYPE_ONGOING ? '10.00' : null,
            'base_amount' => (int) $order->customers_price,
            'amount' => $amount,
            'status' => Commission::STATUS_PAID,
        ]);
    }

    #[Test]
    public function the_list_and_a_detail_page_render_over_http(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $referrer = $this->memberOf($reseller, ['full_name' => 'سارا معرف', 'email' => 'sara@example.test']);
        $buyer = $this->memberOf($reseller, ['full_name' => 'نیما خریدار']);
        $commission = $this->commission($this->order($reseller, $buyer, 100000, 60000), $referrer, 7000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/commissions')
            ->assertOk()
            ->assertSee('سارا معرف')
            ->assertSee('نیما خریدار')
            ->assertSee('7,000')
            ->assertSee('کمیسیون خرید');

        $this->actingAs($owner, 'reseller')->get('/arial/commissions/'.$commission->id)
            ->assertOk()
            ->assertSee('سارا معرف')
            ->assertSee('sara@example.test')
            ->assertSee('نیما خریدار')
            ->assertSee('100,000')
            ->assertSee('60,000')
            ->assertSee('40,000')   // سود سفارش
            ->assertSee('33,000');  // سود پس از کمیسیون
    }

    #[Test]
    public function another_resellers_commission_is_not_found_and_not_listed(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $other = Reseller::factory()->create(['slug' => 'bravo']);
        $otherReferrer = $this->memberOf($other, ['full_name' => 'معرف غریبه']);
        $otherBuyer = $this->memberOf($other);
        $foreign = $this->commission($this->order($other, $otherBuyer), $otherReferrer, 987654);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/commissions/'.$foreign->id)->assertNotFound();
        $this->actingAs($owner, 'reseller')->get('/arial/commissions')
            ->assertOk()
            ->assertDontSee('معرف غریبه')
            ->assertDontSee('987,654');
    }

    #[Test]
    public function opening_the_page_never_writes_to_the_database(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();
        $before = [AffiliateSetting::query()->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count()];

        $this->actingAs($owner, 'reseller')->get('/arial/commissions')->assertOk();

        $this->assertSame($before, [AffiliateSetting::query()->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count()]);
    }

    #[Test]
    public function an_empty_store_shows_guidance_and_the_disabled_commission_notice(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/commissions')
            ->assertOk()
            ->assertSee('هنوز کمیسیونی پرداخت نشده')
            ->assertSee('فعلاً توسط مدیریت Melorin غیرفعال است');

        // ویجت‌ها Lazy بارگذاری می‌شوند (مثل B5.3)؛ حالت خالی‌شان مستقل تست می‌شود
        $this->actingAsReseller($reseller);
        Livewire::test(TopReferrers::class)->assertSee('هنوز معرفی کمیسیون نگرفته است');
        Livewire::test(CommissionSummary::class)->assertSee('سفارشِ کمیسیون‌داری در این فیلتر نیست');
    }

    #[Test]
    public function current_global_terms_are_shown_read_only(): void
    {
        AffiliateSetting::query()->create(['commission_percent' => '12.50', 'commission_validity_days' => 45]);
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/commissions')
            ->assertOk()
            ->assertSee('نرخ فعلی کمیسیون: 12.5٪')
            ->assertSee('تا 45 روز');
    }

    #[Test]
    public function the_table_is_scoped_and_filters_work(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $buyer = $this->memberOf($reseller);

        $c = $this->commission($this->order($reseller, $buyer), $referrer, 1000);
        $b = $this->commission($this->order($reseller, $buyer), $referrer, 2500, Commission::TYPE_FIRST_PURCHASE);
        $refunded = $this->commission($this->order($reseller, $buyer, 100000, 60000, 'refunded'), $referrer, 400);
        $over = $this->commission($this->order($reseller, $buyer, 100000, 99000), $referrer, 1500);
        $foreign = $this->commission($this->order($other, $this->memberOf($other)), $this->memberOf($other), 5);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCommissions::class)
            ->assertCanSeeTableRecords([$c, $b, $refunded, $over])
            ->assertCanNotSeeTableRecords([$foreign])
            ->filterTable('kind', 'bonus')
            ->assertCanSeeTableRecords([$b])
            ->assertCanNotSeeTableRecords([$c, $refunded, $over])
            ->resetTableFilters()
            ->filterTable('attention', 'refunded_order')
            ->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$c, $b, $over])
            ->resetTableFilters()
            ->filterTable('attention', 'exceeds_profit')
            ->assertCanSeeTableRecords([$over])
            ->assertCanNotSeeTableRecords([$c, $b, $refunded])
            ->resetTableFilters()
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$c, $b, $refunded, $over])
            ->resetTableFilters()
            ->filterTable('period', 'today')
            ->assertCanSeeTableRecords([$c, $b, $refunded, $over])
            ->resetTableFilters()
            ->filterTable('period', 'last_month')
            ->assertCanNotSeeTableRecords([$c, $b, $refunded, $over]);
    }

    #[Test]
    public function a_tampered_filter_value_does_not_break_the_page(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $c = $this->commission($this->order($reseller, $this->memberOf($reseller)), $referrer, 1000);
        $this->actingAsReseller($reseller);

        $lw = Livewire::test(ListCommissions::class);
        foreach (['kind', 'attention', 'period', 'status'] as $filter) {
            $lw->set("tableFilters.{$filter}.value", 'hacked\' OR 1=1 --')->assertSuccessful()->assertCanSeeTableRecords([$c]);
        }
    }

    #[Test]
    public function search_finds_by_referrer_name_email_and_order_number(): void
    {
        $reseller = Reseller::factory()->create();
        $sara = $this->memberOf($reseller, ['full_name' => 'سارا یکتا', 'email' => 'sara.unique@example.test']);
        $omid = $this->memberOf($reseller, ['full_name' => 'امید دوم']);
        $buyer = $this->memberOf($reseller, ['full_name' => 'خریدار مشترک']);
        $a = $this->commission($this->order($reseller, $buyer), $sara, 100);
        $b = $this->commission($this->order($reseller, $buyer), $omid, 200);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCommissions::class)
            ->searchTable('سارا یکتا')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable('sara.unique@')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable('خریدار مشترک')->assertCanSeeTableRecords([$a, $b])
            ->searchTable('امید')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    #[Test]
    public function every_sortable_column_sorts_without_error(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $buyer = $this->memberOf($reseller);
        $low = $this->commission($this->order($reseller, $buyer), $referrer, 100);
        $high = $this->commission($this->order($reseller, $buyer), $referrer, 900);
        $this->actingAsReseller($reseller);

        foreach (['created_at', 'type', 'order_id', 'base_amount', 'commission_rate', 'amount', 'order_profit', 'status'] as $column) {
            Livewire::test(ListCommissions::class)->sortTable($column)->assertSuccessful();
            Livewire::test(ListCommissions::class)->sortTable($column, 'desc')->assertSuccessful();
        }

        Livewire::test(ListCommissions::class)
            ->sortTable('amount', 'desc')->assertCanSeeTableRecords([$high, $low], inOrder: true)
            ->sortTable('amount', 'asc')->assertCanSeeTableRecords([$low, $high], inOrder: true);
    }

    #[Test]
    public function the_attention_column_marks_refunded_and_over_profit_rows_only(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $refunded = $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 60000, 'refunded'), $referrer, 400);
        $over = $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 99000), $referrer, 1500);
        $fine = $this->commission($this->order($reseller, $this->memberOf($reseller)), $referrer, 100);
        $bonus = $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 99000), $referrer, 50000, Commission::TYPE_FIRST_PURCHASE);
        $this->actingAsReseller($reseller);

        // نشان ستون (نه صرفاً وجود متن در صفحه: همان متن در گزینه‌های فیلتر هم هست). رکورد با «کلید» داده می‌شود تا از
        // Query واقعیِ جدول (با ستون‌های محاسبه‌شده) خوانده شود، نه مدلِ خامِ تست.
        Livewire::test(ListCommissions::class)
            ->assertTableColumnStateSet('attention', 'روی سفارش بازگشت‌شده', record: $refunded->getKey())
            ->assertTableColumnStateSet('attention', 'بیشتر از سود سفارش', record: $over->getKey())
            ->assertTableColumnStateSet('attention', null, record: $fine->getKey())
            ->assertTableColumnStateSet('attention', null, record: $bonus->getKey());
    }

    #[Test]
    public function the_column_flag_and_the_sql_filters_agree_on_every_combination(): void
    {
        // دو تعریف (PHP در ستون / SQL در فیلتر و شمارنده) باید روی همه‌ی ترکیب‌ها یکی باشند — سود هر سفارش ۴۰۰۰۰
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $buyer = $this->memberOf($reseller);

        foreach ([Commission::TYPE_ONGOING, Commission::TYPE_FIRST_PURCHASE] as $type) {
            foreach (['account_created', 'refunded'] as $status) {
                foreach ([39999, 40000, 40001] as $amount) {
                    $this->commission($this->order($reseller, $buyer, 100000, 60000, $status), $referrer, $amount, $type);
                }
            }
        }
        $this->actingAsReseller($reseller);

        $center = app(\App\Services\Resellers\Commissions\ResellerCommissionCenter::class);
        $viaColumn = $center->query($reseller)->get()
            ->filter(fn (Commission $c) => CommissionResource::attentionOf($c) !== null)
            ->pluck('id')->sort()->values()->all();
        $viaFilters = collect([ 'refunded_order', 'exceeds_profit' ])
            ->flatMap(fn ($flag) => $center->applyAttention($center->query($reseller), $flag)->pluck('commissions.id'))
            ->unique()->sort()->values()->all();

        $this->assertSame($viaFilters, $viaColumn);
        $this->assertCount(12, $center->query($reseller)->get());
        $this->assertSame(count($viaColumn), $center->totals($center->query($reseller))->attentionCount);
        // ۶ بازگشت‌شده (هر دو نوع) + ۱ کمیسیونِ سالمِ بیشتر از سود
        $this->assertSame(7, count($viaColumn));
    }

    #[Test]
    public function a_raw_model_without_computed_columns_is_never_flagged_over_profit(): void
    {
        $reseller = Reseller::factory()->create();
        $raw = $this->commission($this->order($reseller, $this->memberOf($reseller)), $this->memberOf($reseller), 99999);

        $this->assertNull(CommissionResource::attentionOf($raw));
    }

    #[Test]
    public function the_detail_page_explains_refund_and_over_profit_notes(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $referrer = $this->memberOf($reseller);
        $refunded = $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 60000, 'refunded'), $referrer, 400);
        $over = $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 99000), $referrer, 1500);
        $bonus = $this->commission($this->order($reseller, $this->memberOf($reseller)), $referrer, 90000, Commission::TYPE_FIRST_PURCHASE);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/commissions/'.$refunded->id)
            ->assertOk()->assertSee('برنمی‌گردد');
        $this->actingAs($owner, 'reseller')->get('/arial/commissions/'.$over->id)
            ->assertOk()->assertSee('از سود همین سفارش بیشتر است')->assertSee('-500');
        $this->actingAs($owner, 'reseller')->get('/arial/commissions/'.$bonus->id)
            ->assertOk()->assertSee('پاداش معرفی')->assertDontSee('از سود همین سفارش بیشتر است');
    }

    #[Test]
    public function the_summary_widget_follows_the_table_filters(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $buyer = $this->memberOf($reseller);
        $this->commission($this->order($reseller, $buyer), $referrer, 1111);
        $this->commission($this->order($reseller, $buyer), $referrer, 2222, Commission::TYPE_FIRST_PURCHASE);
        $this->actingAsReseller($reseller);

        Livewire::test(CommissionSummary::class)
            ->assertSee('کمیسیون پرداخت‌شده')
            ->assertSee('1,111')
            ->assertSee('2,222');

        Livewire::test(CommissionSummary::class, ['tableFilters' => ['kind' => ['value' => 'bonus']]])
            ->assertSee('2,222')
            ->assertDontSee('1,111');
    }

    #[Test]
    public function the_summary_widget_shows_attention_and_profit_share(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        // سود ۴۰۰۰۰ و کمیسیون ۲۰۰۰۰ ⇒ ۵۰٪
        $this->commission($this->order($reseller, $this->memberOf($reseller)), $referrer, 20000);
        $this->actingAsReseller($reseller);

        Livewire::test(CommissionSummary::class)->assertSee('50٪');

        $this->commission($this->order($reseller, $this->memberOf($reseller), 100000, 60000, 'refunded'), $referrer, 400);
        Livewire::test(CommissionSummary::class)->assertSee('روی سفارش بازگشت‌شده');
    }

    #[Test]
    public function the_top_referrers_widget_lists_only_this_store_ranked_by_total(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $star = $this->memberOf($reseller, ['full_name' => 'ستاره‌ی فروشگاه']);
        $minor = $this->memberOf($reseller, ['full_name' => 'معرف کوچک']);
        $foreign = $this->memberOf($other, ['full_name' => 'ستاره‌ی بیرونی']);
        $buyer = $this->memberOf($reseller);

        $this->commission($this->order($reseller, $buyer), $star, 9000);
        $this->commission($this->order($reseller, $buyer), $minor, 100);
        $this->commission($this->order($other, $this->memberOf($other)), $foreign, 99999);
        $this->actingAsReseller($reseller);

        Livewire::test(TopReferrers::class)
            ->assertSeeInOrder(['ستاره‌ی فروشگاه', 'معرف کوچک'])
            ->assertDontSee('ستاره‌ی بیرونی');
    }

    #[Test]
    public function the_panel_is_read_only(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = $this->memberOf($reseller);
        $c = $this->commission($this->order($reseller, $this->memberOf($reseller)), $referrer, 100);
        $this->actingAsReseller($reseller);

        $this->assertFalse(CommissionResource::canCreate());
        $this->assertFalse(CommissionResource::canEdit($c));
        $this->assertFalse(CommissionResource::canDelete($c));
        $this->assertArrayNotHasKey('create', CommissionResource::getPages());
        $this->assertArrayNotHasKey('edit', CommissionResource::getPages());
    }

    #[Test]
    public function the_list_query_count_does_not_grow_with_rows(): void
    {
        $reseller = Reseller::factory()->create();
        $this->actingAsReseller($reseller);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListCommissions::class)->assertSuccessful();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->commission($this->order($reseller, $this->memberOf($reseller)), $this->memberOf($reseller), 100);
        $few = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->commission($this->order($reseller, $this->memberOf($reseller)), $this->memberOf($reseller), 100);
        }

        $this->assertSame($few, $count());
    }
}
