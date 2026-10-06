<?php

namespace Tests\Feature\Resellers\Marketing;

use App\Models\Broadcast;
use App\Models\Commission;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.6 — مرکز بازاریابی نماینده (Core، فقط‌خواندنی): معرفی‌شده‌های همین فروشگاه، نرخ تبدیل، برترین معرف‌ها
 * و کمپین‌های پیام. کوپن/تخفیف عمداً وجود ندارد (Master §16 DS5).
 */
class ResellerMarketingCenterTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    private ResellerMarketingCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->center = app(ResellerMarketingCenter::class);
    }

    /** عضو فروشگاه که توسط $referrer معرفی شده */
    private function invited(Reseller $reseller, ?User $referrer, array $attributes = []): User
    {
        return $this->memberOf($reseller, array_merge(['referrer_id' => $referrer?->id], $attributes));
    }

    private function order(?Reseller $reseller, User $buyer, int $price = 100000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $buyer->id,
            'customer_account_id' => $this->accountIn($buyer, $reseller)->id,
            'reseller_id' => $reseller?->id,
            'sales_channel' => $reseller ? 'reseller_bot' : 'main_bot',
            'main_price' => $reseller ? null : $price,
            'reseller_price' => $reseller ? (int) ($price * 0.6) : null,
            'customers_price' => $reseller ? $price : null,
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

    private function broadcast(?Reseller $reseller, array $a = []): Broadcast
    {
        return Broadcast::create(array_merge([
            'reseller_id' => $reseller?->id, 'message' => 'سلام', 'status' => 'completed',
            'total_recipients' => 10, 'sent_count' => 9, 'failed_count' => 1,
        ], $a));
    }

    /* ---------------------------------------------------------------- Referral */

    #[Test]
    public function only_referred_members_of_this_store_are_listed(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $referrer = User::factory()->create();

        $mine = $this->invited($reseller, $referrer);
        $this->memberOf($reseller);                           // عضو بدون معرف
        $this->invited($other, $referrer);                    // معرفی‌شده‌ی فروشگاه دیگر
        $this->accountIn($this->invited(null ?? $reseller, $referrer), null); // همان کاربر در Main: رکورد Main شمرده نمی‌شود

        $users = $this->center->referralQuery($reseller)->get()->pluck('user_id')->unique()->all();

        $this->assertSame([$mine->id], array_values(array_intersect($users, [$mine->id])));
        $this->assertCount(2, $this->center->referralQuery($reseller)->get(), 'دو عضو معرفی‌شده‌ی همین فروشگاه');
        $this->assertSame(0, $this->center->referralQuery(Reseller::factory()->create())->count());
    }

    #[Test]
    public function the_referrer_need_not_be_a_member_of_this_store(): void
    {
        $reseller = Reseller::factory()->create();
        $outsider = User::factory()->create(['full_name' => 'معرف بیرونی']); // هیچ حسابی در فروشگاه ندارد
        $this->invited($reseller, $outsider);

        $row = $this->center->referralQuery($reseller)->first();

        $this->assertSame($outsider->id, (int) $row->referrer_user_id);
        $this->assertSame('معرف بیرونی', $row->user->referrer->full_name);
    }

    #[Test]
    public function soft_deleted_users_are_not_counted(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $gone = $this->invited($reseller, $referrer);
        $this->invited($reseller, $referrer);
        $gone->delete();

        $this->assertSame(1, $this->center->referralQuery($reseller)->count());
    }

    #[Test]
    public function purchases_use_the_real_purchase_statuses_and_this_stores_orders_only(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $buyer = $this->invited($reseller, $referrer);

        $this->order($reseller, $buyer, 100000, 'account_created');
        $this->order($reseller, $buyer, 50000, 'paid');
        $this->order($reseller, $buyer, 7000, 'provisioning');
        $this->order($reseller, $buyer, 3000, 'provision_failed');
        $this->order($reseller, $buyer, 999999, 'refunded');
        $this->order($reseller, $buyer, 888888, 'failed');
        $this->order($reseller, $buyer, 777777, 'pending');
        $this->order(null, $buyer, 555555);                         // خرید Main همان کاربر

        $row = $this->center->referralQuery($reseller)->first();

        $this->assertSame(4, (int) $row->purchases);
        $this->assertSame(160000, (int) $row->spent);
        $this->assertNotNull($row->first_purchase_at);
    }

    #[Test]
    public function a_member_without_purchases_has_zero_and_null(): void
    {
        $reseller = Reseller::factory()->create();
        $this->invited($reseller, User::factory()->create());

        $row = $this->center->referralQuery($reseller)->first();

        $this->assertSame(0, (int) $row->purchases);
        $this->assertSame(0, (int) $row->spent);
        $this->assertNull($row->first_purchase_at);
    }

    #[Test]
    public function state_filter_splits_converted_and_waiting_and_ignores_unknown(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $buyer = $this->invited($reseller, $referrer);
        $idle = $this->invited($reseller, $referrer);
        $refundedOnly = $this->invited($reseller, $referrer);
        $this->order($reseller, $buyer);
        $this->order($reseller, $refundedOnly, 100, 'refunded');

        $ids = fn ($s) => $this->center->applyState($this->center->referralQuery($reseller), $s)->get()->pluck('user_id')->all();

        $this->assertSame([$buyer->id], $ids('converted'));
        $this->assertEqualsCanonicalizing([$idle->id, $refundedOnly->id], $ids('waiting'));
        foreach ([null, '', 'x', ['converted']] as $junk) {
            $this->assertCount(3, $ids($junk));
        }
    }

    #[Test]
    public function the_joined_period_filter_is_half_open_and_unknown_means_no_filter(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $now = CarbonImmutable::parse('2026-10-05 12:00:00');
        $a = $this->invited($reseller, $referrer);
        $b = $this->invited($reseller, $referrer);
        $c = $this->invited($reseller, $referrer);
        $set = fn (User $u, string $when) => CustomerAccount::query()->where('user_id', $u->id)->where('reseller_id', $reseller->id)->toBase()->update(['created_at' => $when]);
        $set($a, '2026-10-05 00:00:00');
        $set($b, '2026-10-04 23:59:59');
        $set($c, '2026-10-06 00:00:00');

        $ids = fn ($p) => $this->center->applyJoinedPeriod($this->center->referralQuery($reseller), $p, $now)->get()->pluck('user_id')->all();

        $this->assertSame([$a->id], $ids('today'));
        $this->assertContains($b->id, $ids('7d'));
        $this->assertNotContains($c->id, $ids('7d'));
        $this->assertCount(3, $ids('all'));
        $this->assertCount(3, $ids(null));
    }

    #[Test]
    public function referral_totals_count_invited_converted_referrers_and_revenue(): void
    {
        $reseller = Reseller::factory()->create();
        $r1 = User::factory()->create();
        $r2 = User::factory()->create();
        $a = $this->invited($reseller, $r1);
        $b = $this->invited($reseller, $r1);
        $c = $this->invited($reseller, $r2);
        $this->invited($reseller, $r2);

        $this->order($reseller, $a, 100000);
        $this->order($reseller, $a, 20000);
        $this->order($reseller, $c, 5000);
        $this->order($reseller, $b, 99999, 'refunded');

        $t = $this->center->referralTotals($reseller, $this->center->referralQuery($reseller));

        $this->assertSame(4, $t->invited);
        $this->assertSame(2, $t->converted);
        $this->assertSame(2, $t->referrers);
        $this->assertSame(125000, $t->revenue);
        $this->assertSame(50, $t->conversionPercent());
    }

    #[Test]
    public function conversion_percent_rounds_half_up_and_is_null_without_invitees(): void
    {
        $reseller = Reseller::factory()->create();
        $r = User::factory()->create();
        $this->assertNull($this->center->referralTotals($reseller, $this->center->referralQuery($reseller))->conversionPercent());

        $a = $this->invited($reseller, $r);
        $this->invited($reseller, $r);
        $this->invited($reseller, $r);
        $this->order($reseller, $a);
        $this->assertSame(33, $this->center->referralTotals($reseller, $this->center->referralQuery($reseller))->conversionPercent()); // 1/3

        $b = $this->invited($reseller, $r);
        $this->order($reseller, $b);
        $this->assertSame(50, $this->center->referralTotals($reseller, $this->center->referralQuery($reseller))->conversionPercent()); // 2/4

        $this->invited($reseller, $r);
        $this->invited($reseller, $r);
        $this->order($reseller, $this->invited($reseller, $r));
        // 3/7 = 42.857 ⇒ 43
        $this->assertSame(43, $this->center->referralTotals($reseller, $this->center->referralQuery($reseller))->conversionPercent());
    }

    #[Test]
    public function totals_ignore_ordering_limit_and_follow_filters(): void
    {
        $reseller = Reseller::factory()->create();
        $r = User::factory()->create();
        $a = $this->invited($reseller, $r);
        $this->invited($reseller, $r);
        $this->invited($reseller, $r);
        $this->order($reseller, $a);

        $paged = $this->center->referralQuery($reseller)->orderBy('customer_accounts.id')->limit(1)->offset(1);
        $this->assertSame(3, $this->center->referralTotals($reseller, $paged)->invited);

        $conv = $this->center->applyState($this->center->referralQuery($reseller), 'converted');
        $t = $this->center->referralTotals($reseller, $conv);
        $this->assertSame(1, $t->invited);
        $this->assertSame(1, $t->converted);
        $this->assertSame(100, $t->conversionPercent());
    }

    #[Test]
    public function top_inviters_rank_by_invited_with_deterministic_ties_and_split_earnings(): void
    {
        $reseller = Reseller::factory()->create();
        $star = User::factory()->create(['full_name' => 'پرمعرفی']);
        $tieA = User::factory()->create(['full_name' => 'هم‌رده الف']);
        $tieB = User::factory()->create(['full_name' => 'هم‌رده ب']);
        $low = User::factory()->create(['full_name' => 'کم‌معرفی']);

        $s1 = $this->invited($reseller, $star);
        $this->invited($reseller, $star);
        $this->invited($reseller, $star);
        $this->invited($reseller, $tieA);
        $this->invited($reseller, $tieA);
        $this->invited($reseller, $tieB);
        $this->invited($reseller, $tieB);
        $this->invited($reseller, $low);

        $o1 = $this->order($reseller, $s1);
        $this->commission($o1, $star, 4000);
        $this->commission($o1, $star, 2500, Commission::TYPE_FIRST_PURCHASE);

        $top = $this->center->topInviters($reseller, $this->center->referralQuery($reseller));

        $this->assertSame([$star->id, min($tieA->id, $tieB->id), max($tieA->id, $tieB->id), $low->id], array_map(fn ($r) => $r->userId, $top));
        $first = $top[0];
        $this->assertSame('پرمعرفی', $first->name);
        $this->assertSame(3, $first->invited);
        $this->assertSame(1, $first->converted);
        $this->assertSame(33, $first->conversionPercent());
        $this->assertSame(4000, $first->commissionEarned);
        $this->assertSame(2500, $first->bonusEarned);
        $this->assertSame(6500, $first->totalEarned());
    }

    #[Test]
    public function top_inviters_earnings_exclude_other_stores_and_main(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $referrer = User::factory()->create();
        $mine = $this->invited($reseller, $referrer);
        $theirs = $this->invited($other, $referrer);

        $this->commission($this->order($reseller, $mine), $referrer, 100);
        $this->commission($this->order($other, $theirs), $referrer, 999999);
        $this->commission($this->order(null, $mine), $referrer, 888888);

        $top = $this->center->topInviters($reseller, $this->center->referralQuery($reseller));

        $this->assertSame(100, $top[0]->commissionEarned);
        $this->assertSame(1, $top[0]->invited);
    }

    #[Test]
    public function top_inviters_respect_limit_and_empty_store(): void
    {
        $reseller = Reseller::factory()->create();
        $this->assertSame([], $this->center->topInviters($reseller, $this->center->referralQuery($reseller)));

        foreach (range(1, 7) as $i) {
            $this->invited($reseller, User::factory()->create());
        }

        $this->assertCount(3, $this->center->topInviters($reseller, $this->center->referralQuery($reseller), 3));
        $this->assertCount(5, $this->center->topInviters($reseller, $this->center->referralQuery($reseller)));
    }

    #[Test]
    public function a_nameless_inviter_falls_back_to_email_then_id(): void
    {
        $reseller = Reseller::factory()->create();
        $byEmail = User::factory()->create(['full_name' => null, 'email' => 'inv@example.test']);
        $this->invited($reseller, $byEmail);

        $this->assertSame('inv@example.test', $this->center->topInviters($reseller, $this->center->referralQuery($reseller))[0]->name);
    }

    #[Test]
    public function referral_queries_are_constant_in_number(): void
    {
        $reseller = Reseller::factory()->create();
        $referrer = User::factory()->create();
        foreach (range(1, 6) as $_) {
            $this->order($reseller, $this->invited($reseller, $referrer));
        }

        $count = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame(1, $count(fn () => $this->center->referralTotals($reseller, $this->center->referralQuery($reseller))));
        $this->assertSame(3, $count(fn () => $this->center->topInviters($reseller, $this->center->referralQuery($reseller))));

        $rows = fn () => $this->center->referralQuery($reseller)->get()->each(fn ($c) => [$c->user?->full_name, $c->user?->referrer?->full_name, $c->purchases, $c->spent]);
        $few = $count($rows);
        foreach (range(1, 6) as $_) {
            $this->order($reseller, $this->invited($reseller, $referrer));
        }
        $this->assertSame($few, $count($rows));
    }

    /* -------------------------------------------------------------- Campaigns */

    #[Test]
    public function campaigns_are_only_this_resellers_not_the_main_admins_or_others(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $mine = $this->broadcast($reseller);
        $this->broadcast($other, ['message' => 'غریبه']);
        $this->broadcast(null, ['message' => 'ادمین اصلی']);

        $this->assertSame([$mine->id], $this->center->campaignQuery($reseller)->pluck('broadcasts.id')->all());
    }

    #[Test]
    public function campaign_status_and_period_filters_ignore_unknown_values(): void
    {
        $reseller = Reseller::factory()->create();
        $done = $this->broadcast($reseller, ['status' => 'completed']);
        $failed = $this->broadcast($reseller, ['status' => 'failed']);
        $now = CarbonImmutable::parse('2026-10-05 12:00:00');
        DB::table('broadcasts')->where('id', $done->id)->update(['created_at' => '2026-10-05 00:00:00']);
        DB::table('broadcasts')->where('id', $failed->id)->update(['created_at' => '2026-10-06 00:00:00']);

        $q = fn () => $this->center->campaignQuery($reseller);

        $this->assertSame([$failed->id], $this->center->applyCampaignStatus($q(), 'failed')->pluck('broadcasts.id')->all());
        $this->assertSame([$done->id], $this->center->applyCampaignPeriod($q(), 'today', $now)->pluck('broadcasts.id')->all());
        foreach ([null, '', 'x', ['failed']] as $junk) {
            $this->assertCount(2, $this->center->applyCampaignStatus($q(), $junk)->get());
            $this->assertCount(2, $this->center->applyCampaignPeriod($q(), $junk, $now)->get());
        }
    }

    #[Test]
    public function campaign_totals_and_delivery_percent(): void
    {
        $reseller = Reseller::factory()->create();
        $this->broadcast($reseller, ['total_recipients' => 100, 'sent_count' => 90, 'failed_count' => 10, 'status' => 'completed']);
        $this->broadcast($reseller, ['total_recipients' => 50, 'sent_count' => 20, 'failed_count' => 0, 'status' => 'sending']);
        $this->broadcast($reseller, ['total_recipients' => 0, 'sent_count' => 0, 'failed_count' => 0, 'status' => 'queued']);
        $this->broadcast(null, ['total_recipients' => 9999, 'sent_count' => 9999]);

        $t = $this->center->campaignTotals($this->center->campaignQuery($reseller));

        $this->assertSame(3, $t->campaigns);
        $this->assertSame(150, $t->recipients);
        $this->assertSame(110, $t->sent);
        $this->assertSame(10, $t->failed);
        $this->assertSame(2, $t->active);
        $this->assertSame(73, $t->deliveryPercent()); // 110/150 = 73.33
    }

    #[Test]
    public function campaign_delivery_percent_rounds_half_up_not_down(): void
    {
        $reseller = Reseller::factory()->create();
        $this->broadcast($reseller, ['total_recipients' => 3, 'sent_count' => 2, 'failed_count' => 1]);

        // 2/3 = 66.67% ⇒ 67 (گرد به نزدیک‌ترین، نه ۶۶)
        $this->assertSame(67, $this->center->campaignTotals($this->center->campaignQuery($reseller))->deliveryPercent());
    }

    #[Test]
    public function an_order_of_another_store_attached_to_this_stores_account_is_not_counted(): void
    {
        // داده‌ی ناسازگار (نباید پیش بیاید): سفارشی که به حساب این فروشگاه وصل است ولی reseller_id دیگری دارد
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $member = $this->invited($reseller, User::factory()->create());
        $order = $this->order($reseller, $member, 5000);
        DB::table('orders')->where('id', $order->id)->update(['reseller_id' => $other->id]);

        $row = $this->center->referralQuery($reseller)->first();

        $this->assertSame(0, (int) $row->purchases);
        $this->assertSame(0, (int) $row->spent);
        // و جمع‌ها هم با جدول هم‌خوان‌اند (همان تعریف)
        $this->assertSame(0, $this->center->referralTotals($reseller, $this->center->referralQuery($reseller))->revenue);
    }

    #[Test]
    public function delivery_percent_is_null_without_recipients(): void
    {
        $reseller = Reseller::factory()->create();

        $this->assertNull($this->center->campaignTotals($this->center->campaignQuery($reseller))->deliveryPercent());
    }

    #[Test]
    public function the_service_never_writes(): void
    {
        $reseller = Reseller::factory()->create();
        $r = User::factory()->create();
        $this->order($reseller, $this->invited($reseller, $r));
        $this->broadcast($reseller);

        $count = fn () => [User::query()->count(), CustomerAccount::query()->count(), Order::query()->count(), Commission::query()->count(), Broadcast::query()->count(), DB::table('wallets')->count(), DB::table('audit_logs')->count()];
        $before = $count();

        $q = $this->center->referralQuery($reseller);
        $this->center->referralTotals($reseller, $q);
        $this->center->topInviters($reseller, $q);
        $this->center->campaignTotals($this->center->campaignQuery($reseller));

        $this->assertSame($before, $count());
    }
}
