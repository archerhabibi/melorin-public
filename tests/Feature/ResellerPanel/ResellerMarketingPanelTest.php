<?php

namespace Tests\Feature\ResellerPanel;

use App\Filament\Reseller\Resources\CampaignResource;
use App\Filament\Reseller\Resources\CampaignResource\Pages\ListCampaigns;
use App\Filament\Reseller\Resources\CampaignResource\Widgets\CampaignSummary;
use App\Filament\Reseller\Resources\ReferralResource;
use App\Filament\Reseller\Resources\ReferralResource\Pages\ListReferrals;
use App\Filament\Reseller\Resources\ReferralResource\Widgets\ReferralSummary;
use App\Filament\Reseller\Resources\ReferralResource\Widgets\TopInviters;
use App\Models\AffiliateSetting;
use App\Models\Broadcast;
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
 * B5.6 — لایه‌ی نمایش مرکز بازاریابی نماینده (معرفی و کمپین‌های پیام). منطق در ResellerMarketingCenterTest تست شده؛
 * این‌جا: رندر واقعی، جداسازی، فیلتر/جست‌وجو/مرتب‌سازی، ویجت‌های وابسته به فیلتر جدول، صفحه‌ی جزئیات و فقط‌مشاهده‌بودن.
 */
class ResellerMarketingPanelTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    /** نشت static صفحه‌بندی Livewire به تست‌های بعدی را می‌بندد (همان دلیل پنل‌های B5.4/B5.5). */
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

    private function invited(Reseller $reseller, ?User $referrer, array $attributes = []): User
    {
        return $this->memberOf($reseller, array_merge(['referrer_id' => $referrer?->id], $attributes));
    }

    private function order(Reseller $reseller, User $buyer, int $price = 100000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $buyer->id,
            'customer_account_id' => $this->accountIn($buyer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => (int) ($price * 0.6),
            'customers_price' => $price,
            'status' => $status,
        ]);
    }

    private function broadcast(?Reseller $reseller, array $a = []): Broadcast
    {
        return Broadcast::create(array_merge([
            'reseller_id' => $reseller?->id, 'message' => 'پیام آزمایشی', 'status' => 'completed',
            'total_recipients' => 10, 'sent_count' => 9, 'failed_count' => 1,
        ], $a));
    }

    /** ردیف عضو در جدول معرفی (با کلید، تا از Query واقعی با ستون‌های محاسبه‌شده خوانده شود) */
    private function rowOf(Reseller $reseller, User $member): int
    {
        return (int) \App\Models\CustomerAccount::query()->where('user_id', $member->id)->where('reseller_id', $reseller->id)->value('id');
    }

    /* ---------------------------------------------------------------- Referral */

    #[Test]
    public function the_referral_page_renders_over_http(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $referrer = User::factory()->create(['full_name' => 'سارا معرف']);
        $buyer = $this->invited($reseller, $referrer, ['full_name' => 'نیما معرفی‌شده', 'email' => 'nima@example.test']);
        $this->order($reseller, $buyer, 120000);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/referrals')
            ->assertOk()
            ->assertSee('نیما معرفی‌شده')
            ->assertSee('nima@example.test')
            ->assertSee('سارا معرف')
            ->assertSee('خرید کرده')
            ->assertSee('120,000');
    }

    #[Test]
    public function other_stores_and_non_referred_members_are_never_listed(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $other = Reseller::factory()->create(['slug' => 'bravo']);
        $referrer = User::factory()->create();
        $this->invited($reseller, $referrer, ['full_name' => 'خودی معرفی‌شده']);
        $this->memberOf($reseller, ['full_name' => 'خودی بدون معرف']);
        $this->invited($other, $referrer, ['full_name' => 'بیگانه معرفی‌شده']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/referrals')
            ->assertOk()
            ->assertSee('خودی معرفی‌شده')
            ->assertDontSee('خودی بدون معرف')
            ->assertDontSee('بیگانه معرفی‌شده');
    }

    #[Test]
    public function opening_the_page_never_writes_and_shows_empty_guidance(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();
        $before = [AffiliateSetting::query()->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count(), DB::table('customer_accounts')->count()];

        $this->actingAs($owner, 'reseller')->get('/arial/referrals')
            ->assertOk()
            ->assertSee('هنوز عضو معرفی‌شده‌ای ندارید')
            ->assertSee('فعلاً توسط مدیریت Melorin غیرفعال است');

        $this->assertSame($before, [AffiliateSetting::query()->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count(), DB::table('customer_accounts')->count()]);
    }

    #[Test]
    public function the_current_program_terms_are_shown_read_only(): void
    {
        AffiliateSetting::query()->create([
            'commission_percent' => '7.50', 'commission_validity_days' => 30,
            'referrer_bonus_amount' => 2000, 'customer_bonus_amount' => 1000,
        ]);
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/referrals')
            ->assertOk()
            ->assertSee('معرف 2,000')
            ->assertSee('مشتری 1,000')
            ->assertSee('کمیسیون هر خرید: 7.5٪');
    }

    #[Test]
    public function referral_filters_work_and_tampered_values_are_safe(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $buyer = $this->invited($reseller, $referrer);
        $idle = $this->invited($reseller, $referrer);
        $this->order($reseller, $buyer);
        $this->actingAsReseller($reseller);
        $buyerRow = $this->rowOf($reseller, $buyer);
        $idleRow = $this->rowOf($reseller, $idle);

        Livewire::test(ListReferrals::class)
            ->assertCanSeeTableRecords([$buyerRow, $idleRow], inOrder: false)
            ->filterTable('state', 'converted')->assertCanSeeTableRecords([$buyerRow])->assertCanNotSeeTableRecords([$idleRow])
            ->resetTableFilters()
            ->filterTable('state', 'waiting')->assertCanSeeTableRecords([$idleRow])->assertCanNotSeeTableRecords([$buyerRow])
            ->resetTableFilters()
            ->filterTable('joined', 'last_month')->assertCanNotSeeTableRecords([$buyerRow, $idleRow]);

        $lw = Livewire::test(ListReferrals::class);
        foreach (['state', 'joined'] as $filter) {
            $lw->set("tableFilters.{$filter}.value", 'hacked\' OR 1=1 --')->assertSuccessful()->assertCanSeeTableRecords([$buyerRow, $idleRow]);
        }
    }

    #[Test]
    public function search_finds_by_member_and_by_referrer(): void
    {
        $reseller = Reseller::factory()->create();
        $r1 = User::factory()->create(['full_name' => 'معرف الف']);
        $r2 = User::factory()->create(['full_name' => 'معرف ب']);
        $a = $this->invited($reseller, $r1, ['full_name' => 'عضو یکتا', 'email' => 'unique.member@example.test']);
        $b = $this->invited($reseller, $r2, ['full_name' => 'عضو دوم']);
        $this->actingAsReseller($reseller);
        [$ra, $rb] = [$this->rowOf($reseller, $a), $this->rowOf($reseller, $b)];

        Livewire::test(ListReferrals::class)
            ->searchTable('عضو یکتا')->assertCanSeeTableRecords([$ra])->assertCanNotSeeTableRecords([$rb])
            ->searchTable('unique.member@')->assertCanSeeTableRecords([$ra])->assertCanNotSeeTableRecords([$rb])
            ->searchTable('معرف ب')->assertCanSeeTableRecords([$rb])->assertCanNotSeeTableRecords([$ra]);
    }

    #[Test]
    public function every_sortable_column_sorts_and_orders_by_spend(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $big = $this->invited($reseller, $referrer);
        $small = $this->invited($reseller, $referrer);
        $this->order($reseller, $big, 900000);
        $this->order($reseller, $small, 1000);
        $this->actingAsReseller($reseller);

        foreach (['created_at', 'purchases', 'spent', 'first_purchase_at'] as $column) {
            Livewire::test(ListReferrals::class)->sortTable($column)->assertSuccessful();
            Livewire::test(ListReferrals::class)->sortTable($column, 'desc')->assertSuccessful();
        }

        Livewire::test(ListReferrals::class)
            ->sortTable('spent', 'desc')->assertCanSeeTableRecords([$this->rowOf($reseller, $big), $this->rowOf($reseller, $small)], inOrder: true)
            ->sortTable('spent', 'asc')->assertCanSeeTableRecords([$this->rowOf($reseller, $small), $this->rowOf($reseller, $big)], inOrder: true);
    }

    #[Test]
    public function the_state_column_labels_rows(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $buyer = $this->invited($reseller, $referrer);
        $idle = $this->invited($reseller, $referrer);
        $this->order($reseller, $buyer);
        $this->actingAsReseller($reseller);

        Livewire::test(ListReferrals::class)
            ->assertTableColumnStateSet('state', 'خرید کرده', record: $this->rowOf($reseller, $buyer))
            ->assertTableColumnStateSet('state', 'هنوز خرید نکرده', record: $this->rowOf($reseller, $idle));
    }

    #[Test]
    public function the_summary_widget_follows_the_table_filters(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $buyer = $this->invited($reseller, $referrer);
        $this->invited($reseller, $referrer);
        $this->order($reseller, $buyer, 123456);
        $this->actingAsReseller($reseller);

        Livewire::test(ReferralSummary::class)
            ->assertSee('اعضای معرفی‌شده')
            ->assertSee('50٪')
            ->assertSee('123,456');

        Livewire::test(ReferralSummary::class, ['tableFilters' => ['state' => ['value' => 'waiting']]])
            ->assertDontSee('123,456')
            ->assertSee('0٪');
    }

    #[Test]
    public function the_summary_widget_handles_an_empty_store(): void
    {
        $reseller = Reseller::factory()->create();
        $this->actingAsReseller($reseller);

        Livewire::test(ReferralSummary::class)->assertSuccessful()->assertSee('معرفی‌ای نیست');
        Livewire::test(TopInviters::class)->assertSee('هنوز معرفی‌ای ثبت نشده است');
    }

    #[Test]
    public function the_top_inviters_widget_ranks_by_invited_and_splits_earnings(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $star = User::factory()->create(['full_name' => 'ستاره‌ی فروشگاه']);
        $minor = User::factory()->create(['full_name' => 'معرف کوچک']);
        $foreign = User::factory()->create(['full_name' => 'ستاره‌ی بیرونی']);
        $a = $this->invited($reseller, $star);
        $this->invited($reseller, $star);
        $this->invited($reseller, $minor);
        foreach (range(1, 5) as $_) {
            $this->invited($other, $foreign);
        }
        $order = $this->order($reseller, $a);
        foreach ([[Commission::TYPE_ONGOING, 4321], [Commission::TYPE_FIRST_PURCHASE, 1234]] as [$type, $amount]) {
            Commission::create([
                'referrer_id' => $star->id, 'referred_user_id' => $a->id, 'referred_customer_account_id' => $order->customer_account_id,
                'order_id' => $order->id, 'type' => $type, 'commission_rate' => $type === Commission::TYPE_ONGOING ? '10.00' : null,
                'base_amount' => 100000, 'amount' => $amount, 'status' => Commission::STATUS_PAID,
            ]);
        }
        $this->actingAsReseller($reseller);

        Livewire::test(TopInviters::class)
            ->assertSeeInOrder(['ستاره‌ی فروشگاه', 'معرف کوچک'])
            ->assertSee('4,321')
            ->assertSee('1,234')
            ->assertDontSee('ستاره‌ی بیرونی');
    }

    #[Test]
    public function the_referral_panel_is_read_only(): void
    {
        $reseller = Reseller::factory()->create();
        $row = \App\Models\CustomerAccount::query()->where('reseller_id', $reseller->id)->first() ?? $this->accountIn($this->memberOf($reseller), $reseller);
        $this->actingAsReseller($reseller);

        $this->assertFalse(ReferralResource::canCreate());
        $this->assertFalse(ReferralResource::canEdit($row));
        $this->assertFalse(ReferralResource::canDelete($row));
        $this->assertSame(['index'], array_keys(ReferralResource::getPages()));
    }

    #[Test]
    public function the_referral_list_query_count_does_not_grow_with_rows(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $this->actingAsReseller($reseller);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListReferrals::class)->assertSuccessful();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->order($reseller, $this->invited($reseller, $referrer));
        $few = $count();
        foreach (range(1, 8) as $_) {
            $this->order($reseller, $this->invited($reseller, $referrer));
        }

        $this->assertSame($few, $count());
    }

    /* ---------------------------------------------------------------- Campaigns */

    #[Test]
    public function the_campaign_list_renders_and_hides_main_admin_and_other_resellers(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $other = Reseller::factory()->create(['slug' => 'bravo']);
        $mine = $this->broadcast($reseller, ['message' => 'تخفیف ویژه‌ی من']);
        $this->broadcast($other, ['message' => 'پیام بیگانه']);
        $this->broadcast(null, ['message' => 'پیام ادمین اصلی']);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/campaigns')
            ->assertOk()
            ->assertSee('تخفیف ویژه‌ی من')
            ->assertSee('90٪')
            ->assertDontSee('پیام بیگانه')
            ->assertDontSee('پیام ادمین اصلی');

        $this->actingAs($owner, 'reseller')->get('/arial/campaigns/'.$mine->id)
            ->assertOk()
            ->assertSee('تخفیف ویژه‌ی من')
            ->assertSee('کامل‌شده');
    }

    #[Test]
    public function another_resellers_or_the_admins_campaign_detail_is_not_found(): void
    {
        $reseller = Reseller::factory()->create(['slug' => 'arial']);
        $other = Reseller::factory()->create(['slug' => 'bravo']);
        $theirs = $this->broadcast($other);
        $admins = $this->broadcast(null);
        $owner = $this->actingAsReseller($reseller)->fresh();

        $this->actingAs($owner, 'reseller')->get('/arial/campaigns/'.$theirs->id)->assertNotFound();
        $this->actingAs($owner, 'reseller')->get('/arial/campaigns/'.$admins->id)->assertNotFound();
    }

    #[Test]
    public function campaign_filters_search_and_sort_work(): void
    {
        $reseller = Reseller::factory()->create();
        $done = $this->broadcast($reseller, ['message' => 'عید مبارک', 'status' => 'completed', 'total_recipients' => 100]);
        $failed = $this->broadcast($reseller, ['message' => 'قطعی سرویس', 'status' => 'failed', 'total_recipients' => 5]);
        $this->actingAsReseller($reseller);

        Livewire::test(ListCampaigns::class)
            ->assertCanSeeTableRecords([$done, $failed])
            ->filterTable('status', 'failed')->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$done])
            ->resetTableFilters()
            ->filterTable('period', 'last_month')->assertCanNotSeeTableRecords([$done, $failed])
            ->resetTableFilters()
            ->searchTable('عید')->assertCanSeeTableRecords([$done])->assertCanNotSeeTableRecords([$failed])
            ->searchTable('%')->assertCanNotSeeTableRecords([$done, $failed]);

        foreach (['created_at', 'status', 'total_recipients', 'sent_count', 'failed_count'] as $column) {
            Livewire::test(ListCampaigns::class)->sortTable($column)->assertSuccessful();
        }
        Livewire::test(ListCampaigns::class)
            ->sortTable('total_recipients', 'desc')->assertCanSeeTableRecords([$done, $failed], inOrder: true);

        $lw = Livewire::test(ListCampaigns::class);
        foreach (['status', 'period'] as $filter) {
            $lw->set("tableFilters.{$filter}.value", 'x\' or 1=1')->assertSuccessful()->assertCanSeeTableRecords([$done, $failed]);
        }
    }

    #[Test]
    public function the_delivery_rate_column_handles_zero_recipients(): void
    {
        $reseller = Reseller::factory()->create();
        $none = $this->broadcast($reseller, ['total_recipients' => 0, 'sent_count' => 0, 'failed_count' => 0, 'status' => 'queued']);
        $some = $this->broadcast($reseller, ['total_recipients' => 3, 'sent_count' => 2, 'failed_count' => 1]);
        $this->actingAsReseller($reseller);

        $this->assertNull(CampaignResource::deliveryPercent($none));
        $this->assertSame(67, CampaignResource::deliveryPercent($some)); // 2/3
        Livewire::test(ListCampaigns::class)
            ->assertTableColumnStateSet('delivery', '—', record: $none->getKey())
            ->assertTableColumnStateSet('delivery', '67٪', record: $some->getKey());
    }

    #[Test]
    public function the_campaign_widget_follows_the_table_filters(): void
    {
        $reseller = Reseller::factory()->create();
        $this->broadcast($reseller, ['status' => 'completed', 'total_recipients' => 1111, 'sent_count' => 1000, 'failed_count' => 111]);
        $this->broadcast($reseller, ['status' => 'failed', 'total_recipients' => 2222, 'sent_count' => 0, 'failed_count' => 2222]);
        $this->actingAsReseller($reseller);

        Livewire::test(CampaignSummary::class)->assertSee('3,333');
        Livewire::test(CampaignSummary::class, ['tableFilters' => ['status' => ['value' => 'failed']]])
            ->assertSee('2,222')
            ->assertDontSee('3,333');
    }

    #[Test]
    public function the_campaign_panel_is_read_only(): void
    {
        $reseller = Reseller::factory()->create();
        $b = $this->broadcast($reseller);
        $this->actingAsReseller($reseller);

        $this->assertFalse(CampaignResource::canCreate());
        $this->assertFalse(CampaignResource::canEdit($b));
        $this->assertFalse(CampaignResource::canDelete($b));
        $this->assertSame(['index', 'view'], array_keys(CampaignResource::getPages()));
    }

    #[Test]
    public function no_coupon_or_discount_surface_exists(): void
    {
        // Master §16 DS5: موتور Discount در کد وجود ندارد؛ مرکز بازاریابی نباید نمایی از آن بسازد.
        $names = collect(Filament::getPanel('reseller')->getResources())->map(fn ($r) => strtolower(class_basename($r)));

        $this->assertFalse($names->contains(fn ($n) => str_contains($n, 'coupon') || str_contains($n, 'discount')));
    }
}
