<?php

namespace Tests\Feature\Resellers\Commissions;

use App\Models\AffiliateSetting;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.4 — مرکز کمیسیون نماینده (Core، فقط‌خواندنی): جداسازی فروشگاه، جدایی کمیسیون از پاداش معرفی، جمع‌ها،
 * فیلترها (با مقدار نامعتبر بی‌اثر)، «نیازمند توجه»، برترین معرف‌ها، N+1 و فقط‌خواندنی‌بودن.
 */
class ResellerCommissionCenterTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    private ResellerCommissionCenter $center;

    protected function setUp(): void
    {
        parent::setUp();

        $this->center = app(ResellerCommissionCenter::class);
    }

    /** سفارشِ فروشگاه نماینده؛ سود = $price − $supply */
    private function order(?Reseller $reseller, User $buyer, int $price = 100000, int $supply = 60000, string $status = 'account_created'): Order
    {
        return Order::factory()->create([
            'user_id' => $buyer->id,
            'customer_account_id' => $this->accountIn($buyer, $reseller)->id,
            'reseller_id' => $reseller?->id,
            'sales_channel' => $reseller ? 'reseller_bot' : 'main_bot',
            'main_price' => $reseller ? null : $price,
            'reseller_price' => $reseller ? $supply : null,
            'customers_price' => $reseller ? $price : null,
            'status' => $status,
        ]);
    }

    private function commission(Order $order, User $referrer, int $amount, string $type = Commission::TYPE_ONGOING, array $extra = []): Commission
    {
        return Commission::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $order->user_id,
            'referrer_customer_account_id' => null,
            'referred_customer_account_id' => $order->customer_account_id,
            'order_id' => $order->id,
            'type' => $type,
            'commission_rate' => $type === Commission::TYPE_ONGOING ? '10.00' : null,
            'base_amount' => (int) ($order->customers_price ?? $order->main_price),
            'amount' => $amount,
            'status' => Commission::STATUS_PAID,
            ...$extra,
        ]);
    }

    /** @return array{0: Reseller, 1: User, 2: User} نماینده، معرف، مشتری */
    private function store(): array
    {
        $reseller = Reseller::factory()->create();

        return [$reseller, $this->memberOf($reseller), $this->memberOf($reseller)];
    }

    #[Test]
    public function only_commissions_of_this_resellers_orders_are_visible(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $other = Reseller::factory()->create();

        $mine = $this->commission($this->order($reseller, $buyer), $referrer, 1000);
        // همان معرف و همان مشتری، ولی در فروشگاه دیگر / فروشگاه اصلی
        $this->commission($this->order($other, $buyer), $referrer, 7777);
        $this->commission($this->order(null, $buyer), $referrer, 8888);

        $ids = $this->center->query($reseller)->pluck('commissions.id')->all();

        $this->assertSame([$mine->id], $ids);
    }

    #[Test]
    public function find_returns_only_own_commissions_and_ignores_garbage_ids(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $other = Reseller::factory()->create();
        $otherBuyer = $this->memberOf($other);

        $mine = $this->commission($this->order($reseller, $buyer), $referrer, 1000);
        $foreign = $this->commission($this->order($other, $otherBuyer), $referrer, 5000);

        $this->assertSame($mine->id, $this->center->find($reseller, $mine->id)?->id);
        $this->assertSame($mine->id, $this->center->find($reseller, (string) $mine->id)?->id);
        $this->assertNull($this->center->find($reseller, $foreign->id));
        $this->assertNull($this->center->find($reseller, 999999));
        $this->assertNull($this->center->find($reseller, '1 or 1=1'));
        $this->assertNull($this->center->find($reseller, '-1'));
    }

    #[Test]
    public function commission_and_referral_bonus_are_counted_separately_never_mixed(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $order = $this->order($reseller, $buyer);

        $this->commission($order, $referrer, 4000);
        $this->commission($order, $referrer, 2500, Commission::TYPE_FIRST_PURCHASE);

        $t = $this->center->totals($this->center->query($reseller));

        $this->assertSame(1, $t->commissionCount);
        $this->assertSame(4000, $t->commissionAmount);
        $this->assertSame(1, $t->bonusCount);
        $this->assertSame(2500, $t->bonusAmount);
        $this->assertSame(6500, $t->totalAmount());
        // مبنای «سهم از سود» فقط سفارش‌های کمیسیون‌دار است؛ پاداش در آن سهمی ندارد
        $this->assertSame(40000, $t->profitOnCommissionedOrders);
        $this->assertSame(10, $t->shareOfProfit());
    }

    #[Test]
    public function totals_count_distinct_referrers_and_referred_customers(): void
    {
        [$reseller, $referrerA, $buyerA] = $this->store();
        $referrerB = $this->memberOf($reseller);
        $buyerB = $this->memberOf($reseller);

        $this->commission($this->order($reseller, $buyerA), $referrerA, 100);
        $this->commission($this->order($reseller, $buyerA), $referrerA, 100);
        $this->commission($this->order($reseller, $buyerB), $referrerB, 100);

        $t = $this->center->totals($this->center->query($reseller));

        $this->assertSame(3, $t->commissionCount);
        $this->assertSame(2, $t->referrers);
        $this->assertSame(2, $t->referredCustomers);
    }

    #[Test]
    public function an_empty_store_gives_zero_totals_and_no_share(): void
    {
        $reseller = Reseller::factory()->create();

        $t = $this->center->totals($this->center->query($reseller));

        $this->assertTrue($t->isEmpty());
        $this->assertSame(0, $t->totalAmount());
        $this->assertNull($t->shareOfProfit());
        $this->assertSame([], $this->center->topReferrers($this->center->query($reseller)));
    }

    #[Test]
    public function kind_filter_splits_and_unknown_values_are_ignored(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $order = $this->order($reseller, $buyer);
        $c = $this->commission($order, $referrer, 4000);
        $b = $this->commission($order, $referrer, 2500, Commission::TYPE_FIRST_PURCHASE);

        $q = fn ($kind) => $this->center->applyKind($this->center->query($reseller), $kind)->pluck('commissions.id')->all();

        $this->assertSame([$c->id], $q('commission'));
        $this->assertSame([$b->id], $q('bonus'));
        foreach ([null, '', 'x', ['commission'], 'ongoing_commission'] as $junk) {
            $this->assertEqualsCanonicalizing([$c->id, $b->id], $q($junk), 'junk kind must not filter');
        }
    }

    #[Test]
    public function status_filter_and_pending_totals(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $order = $this->order($reseller, $buyer);
        $paid = $this->commission($order, $referrer, 1000);
        $pending = $this->commission($this->order($reseller, $buyer), $referrer, 300, Commission::TYPE_ONGOING, ['status' => Commission::STATUS_PENDING]);

        $q = fn ($s) => $this->center->applyStatus($this->center->query($reseller), $s)->pluck('commissions.id')->all();

        $this->assertSame([$paid->id], $q('paid'));
        $this->assertSame([$pending->id], $q('pending'));
        $this->assertCount(2, $q('nonsense'));

        $t = $this->center->totals($this->center->query($reseller));
        $this->assertSame(1, $t->pendingCount);
        $this->assertSame(300, $t->pendingAmount);
    }

    #[Test]
    public function refunded_order_attention_covers_both_kinds_and_is_not_reversed(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $refunded = $this->order($reseller, $buyer, 100000, 60000, 'refunded');
        $fine = $this->order($reseller, $buyer);

        $a = $this->commission($refunded, $referrer, 4000);
        $b = $this->commission($refunded, $referrer, 2500, Commission::TYPE_FIRST_PURCHASE);
        $this->commission($fine, $referrer, 999);

        $flagged = $this->center->applyAttention($this->center->query($reseller), 'refunded_order')->pluck('commissions.id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $flagged);

        $t = $this->center->totals($this->center->query($reseller));
        $this->assertSame(2, $t->refundedOrderCount);
        $this->assertSame(6500, $t->refundedOrderAmount);

        // خودِ رکورد دست‌نخورده می‌ماند (Master §10: Refund، Commission را Reverse نمی‌کند)
        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame(4000, (int) $a->fresh()->amount);
    }

    #[Test]
    public function exceeds_profit_is_strict_ongoing_only_and_equality_is_not_flagged(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();

        // سود هر سفارش = ۴۰۰۰۰
        $over = $this->commission($this->order($reseller, $buyer), $referrer, 40001);
        $equal = $this->commission($this->order($reseller, $buyer), $referrer, 40000);
        $under = $this->commission($this->order($reseller, $buyer), $referrer, 100);
        // پاداش ثابتِ بزرگ‌تر از سود، «کمیسیونِ بیش از سود» نیست (مفهوم دیگری است)
        $bonus = $this->commission($this->order($reseller, $buyer), $referrer, 90000, Commission::TYPE_FIRST_PURCHASE);

        $flagged = $this->center->applyAttention($this->center->query($reseller), 'exceeds_profit')->pluck('commissions.id')->all();

        $this->assertSame([$over->id], $flagged);
        $this->assertNotContains($equal->id, $flagged);
        $this->assertNotContains($under->id, $flagged);
        $this->assertNotContains($bonus->id, $flagged);
        $this->assertSame(1, $this->center->totals($this->center->query($reseller))->exceedsProfitCount);
    }

    #[Test]
    public function attention_count_counts_each_record_once_even_when_both_flags_apply(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();

        // هم روی سفارش بازگشت‌شده، هم بیشتر از سود (سود ۴۰۰۰۰) ⇒ یک بار
        $this->commission($this->order($reseller, $buyer, 100000, 60000, 'refunded'), $referrer, 50000);
        // فقط بازگشت‌شده
        $this->commission($this->order($reseller, $buyer, 100000, 60000, 'refunded'), $referrer, 100);
        // فقط بیشتر از سود
        $this->commission($this->order($reseller, $buyer), $referrer, 45000);
        // سالم
        $this->commission($this->order($reseller, $buyer), $referrer, 100);

        $t = $this->center->totals($this->center->query($reseller));

        $this->assertSame(3, $t->attentionCount);
        $this->assertSame(2, $t->refundedOrderCount);
        $this->assertSame(2, $t->exceedsProfitCount);
    }

    #[Test]
    public function an_order_with_no_profit_flags_any_positive_commission(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $c = $this->commission($this->order($reseller, $buyer, 50000, 50000), $referrer, 1);

        $flagged = $this->center->applyAttention($this->center->query($reseller), 'exceeds_profit')->pluck('commissions.id')->all();

        $this->assertSame([$c->id], $flagged);
        // مبنای سود صفر ⇒ درصدِ بی‌معنی نشان داده نمی‌شود
        $this->assertNull($this->center->totals($this->center->query($reseller))->shareOfProfit());
    }

    #[Test]
    public function share_of_profit_uses_integer_half_up_rounding(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        // سود ۳ ⇒ کمیسیون ۱ = ۳۳٫۳٪ ⇒ ۳۳ ؛ کمیسیون ۲ از سود ۳ = ۶۶٫۶٪ ⇒ ۶۷
        $this->commission($this->order($reseller, $buyer, 103, 100), $referrer, 1);
        $this->assertSame(33, $this->center->totals($this->center->query($reseller))->shareOfProfit());

        Commission::query()->update(['amount' => 2]);
        $this->assertSame(67, $this->center->totals($this->center->query($reseller))->shareOfProfit());
    }

    #[Test]
    public function attention_filter_ignores_unknown_values(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $this->commission($this->order($reseller, $buyer), $referrer, 100);
        $this->commission($this->order($reseller, $buyer), $referrer, 100);

        foreach ([null, '', 'x', ['refunded_order']] as $junk) {
            $this->assertCount(2, $this->center->applyAttention($this->center->query($reseller), $junk)->get());
        }
    }

    #[Test]
    public function period_filter_is_half_open_and_unknown_means_no_filter(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $now = CarbonImmutable::parse('2026-10-05 12:00:00');
        $order = $this->order($reseller, $buyer);

        $at = function (string $when) use ($order, $referrer): int {
            $commission = $this->commission($order, $referrer, 100);
            // created_at در fillable نیست؛ زمان با به‌روزرسانی مستقیم تنظیم می‌شود
            DB::table('commissions')->where('id', $commission->id)->update(['created_at' => $when, 'updated_at' => $when]);

            return $commission->id;
        };
        $startOfToday = $at('2026-10-05 00:00:00');
        $lastSecondYesterday = $at('2026-10-04 23:59:59');
        $nextMidnight = $at('2026-10-06 00:00:00');

        $ids = fn ($p) => $this->center->applyPeriod($this->center->query($reseller), $p, $now)->pluck('commissions.id')->all();

        $this->assertSame([$startOfToday], $ids('today'));
        $this->assertContains($lastSecondYesterday, $ids('7d'));
        $this->assertNotContains($nextMidnight, $ids('7d'), 'پایان بازه انحصاری است');
        $this->assertCount(3, $ids('all'));
        $this->assertCount(3, $ids(null));
        $this->assertCount(3, $ids(['today']));
    }

    #[Test]
    public function totals_ignore_ordering_limit_and_eager_loads_of_the_table_query(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        foreach ([100, 200, 300] as $amount) {
            $this->commission($this->order($reseller, $buyer), $referrer, $amount);
        }

        $tableQuery = $this->center->query($reseller)->orderBy('amount')->limit(1)->offset(1);

        $this->assertSame(600, $this->center->totals($tableQuery)->commissionAmount);
    }

    #[Test]
    public function totals_follow_the_filtered_query_so_numbers_match_the_table(): void
    {
        [$reseller, $referrerA, $buyer] = $this->store();
        $referrerB = $this->memberOf($reseller);
        $this->commission($this->order($reseller, $buyer), $referrerA, 1000);
        $this->commission($this->order($reseller, $buyer), $referrerB, 3000);

        $query = $this->center->query($reseller)->where('commissions.referrer_id', $referrerB->id);

        $t = $this->center->totals($query);
        $this->assertSame(3000, $t->commissionAmount);
        $this->assertSame(1, $t->referrers);
    }

    #[Test]
    public function top_referrers_rank_by_total_split_kinds_and_break_ties_by_id(): void
    {
        [$reseller, $low, $buyer] = $this->store();
        $high = $this->memberOf($reseller, ['full_name' => 'معرف پردرآمد']);
        $tieA = $this->memberOf($reseller, ['full_name' => 'هم‌رده الف']);
        $tieB = $this->memberOf($reseller, ['full_name' => 'هم‌رده ب']);
        $buyer2 = $this->memberOf($reseller);

        $this->commission($this->order($reseller, $buyer), $high, 5000);
        $this->commission($this->order($reseller, $buyer2), $high, 3000);
        $this->commission($this->order($reseller, $buyer), $high, 1500, Commission::TYPE_FIRST_PURCHASE);
        $this->commission($this->order($reseller, $buyer), $tieA, 2000);
        $this->commission($this->order($reseller, $buyer), $tieB, 2000);
        $this->commission($this->order($reseller, $buyer), $low, 10);

        $top = $this->center->topReferrers($this->center->query($reseller));

        $this->assertSame([$high->id, min($tieA->id, $tieB->id), max($tieA->id, $tieB->id), $low->id], array_map(fn ($r) => $r->userId, $top));

        $first = $top[0];
        $this->assertSame('معرف پردرآمد', $first->name);
        $this->assertSame(2, $first->commissionCount);
        $this->assertSame(8000, $first->commissionAmount);
        $this->assertSame(1500, $first->bonusAmount);
        $this->assertSame(9500, $first->totalAmount());
        $this->assertSame(2, $first->referredCustomers);
        $this->assertNotNull($first->lastPaidAt);
    }

    #[Test]
    public function top_referrers_respect_the_limit_and_the_store_scope(): void
    {
        [$reseller, , $buyer] = $this->store();
        $other = Reseller::factory()->create();
        $otherBuyer = $this->memberOf($other);
        $foreignStar = $this->memberOf($other, ['full_name' => 'ستاره‌ی دیگری']);

        for ($i = 1; $i <= 7; $i++) {
            $this->commission($this->order($reseller, $buyer), $this->memberOf($reseller), $i * 100);
        }
        $this->commission($this->order($other, $otherBuyer), $foreignStar, 9999999);

        $top = $this->center->topReferrers($this->center->query($reseller), 3);

        $this->assertCount(3, $top);
        $this->assertSame([700, 600, 500], array_map(fn ($r) => $r->totalAmount(), $top));
        $this->assertNotContains('ستاره‌ی دیگری', array_map(fn ($r) => $r->name, $top));
    }

    #[Test]
    public function a_referrer_without_name_falls_back_to_email_then_id(): void
    {
        [$reseller, , $buyer] = $this->store();
        $byEmail = $this->memberOf($reseller, ['full_name' => null, 'email' => 'ref@example.test']);

        $this->commission($this->order($reseller, $buyer), $byEmail, 100);

        $this->assertSame('ref@example.test', $this->center->topReferrers($this->center->query($reseller))[0]->name);
    }

    #[Test]
    public function the_listing_query_count_does_not_depend_on_the_number_of_rows(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $count = function () use ($reseller): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->center->query($reseller)->get();
            // لمس همه‌ی روابطی که جدول می‌خواند
            $rows->each(fn ($c) => [$c->referrer?->full_name, $c->referredUser?->full_name, $c->order?->product?->name, $c->order_status, $c->order_profit]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->commission($this->order($reseller, $buyer), $referrer, 100);
        $few = $count();

        for ($i = 0; $i < 12; $i++) {
            $this->commission($this->order($reseller, $this->memberOf($reseller)), $this->memberOf($reseller), 100);
        }
        $many = $count();

        $this->assertSame($few, $many);
    }

    #[Test]
    public function totals_and_top_referrers_use_a_fixed_number_of_queries(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        for ($i = 0; $i < 6; $i++) {
            $this->commission($this->order($reseller, $buyer), $this->memberOf($reseller), 100);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->center->totals($this->center->query($reseller));
        $totalsQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->center->topReferrers($this->center->query($reseller));
        $topQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $totalsQueries);
        $this->assertSame(2, $topQueries);
    }

    #[Test]
    public function terms_are_read_only_and_never_create_the_settings_row(): void
    {
        $this->assertSame(0, AffiliateSetting::query()->count());

        $terms = $this->center->terms();

        $this->assertSame(0, AffiliateSetting::query()->count(), 'خواندن شرایط نباید رکورد بسازد');
        $this->assertFalse($terms->commissionEnabled());
        $this->assertSame(0, $terms->validityDays);

        AffiliateSetting::query()->create([
            'commission_percent' => '7.50', 'commission_validity_days' => 90,
            'referrer_bonus_amount' => 2000, 'customer_bonus_amount' => 1000,
        ]);

        $terms = $this->center->terms();
        $this->assertTrue($terms->commissionEnabled());
        $this->assertSame('7.5', $terms->percentLabel());
        $this->assertSame(90, $terms->validityDays);
        $this->assertSame(2000, $terms->referrerBonus);
        $this->assertSame(1000, $terms->customerBonus);
    }

    #[Test]
    public function the_service_never_writes(): void
    {
        [$reseller, $referrer, $buyer] = $this->store();
        $this->commission($this->order($reseller, $buyer), $referrer, 100);

        $before = [Commission::query()->count(), DB::table('wallets')->count(), DB::table('wallet_transactions')->count(), DB::table('audit_logs')->count()];

        $q = $this->center->query($reseller);
        $this->center->totals($q);
        $this->center->topReferrers($q);
        $this->center->terms();
        $this->center->find($reseller, 1);

        $after = [Commission::query()->count(), DB::table('wallets')->count(), DB::table('wallet_transactions')->count(), DB::table('audit_logs')->count()];
        $this->assertSame($before, $after);
    }
}
