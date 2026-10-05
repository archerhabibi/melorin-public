<?php

namespace Tests\Feature\ResellerPanel;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\Wallet;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Dashboard\ResellerAlert;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use App\Services\Resellers\Dashboard\ResellerSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * B5.1 — سرویس داشبورد نماینده (Core). فقط‌خواندنی، Scope‌شده، Aggregate در DB، همه‌ی مبالغ int.
 */
class ResellerDashboardServiceTest extends TestCase
{
    use RefreshDatabase;
    use StoreMembers;

    /** چهارشنبه ۱۵ مهر ۱۴۰۵ (۷ اکتبر ۲۰۲۶)، ظهر */
    private const NOW = '2026-10-07 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::NOW);
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): ResellerDashboardService
    {
        return app(ResellerDashboardService::class);
    }

    /** سفارش فروشگاه نماینده؛ $price = customers_price، $cost = reseller_price */
    private function sale(Reseller $reseller, int $price, int $cost, string $status = 'account_created', string $at = '2026-10-07 09:00:00', bool $renewal = false, ?int $customerAccountId = null): Order
    {
        $customer = $this->memberOf($reseller);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'customer_account_id' => $customerAccountId ?? $this->accountIn($customer, $reseller)->id,
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $cost,
            'customers_price' => $price,
            'status' => $status,
            'renews_account_id' => $renewal ? 999 : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /* ------------------------------ بازه‌ها ------------------------------ */

    #[Test]
    public function ranges_are_half_open_and_previous_ranges_are_equal_length(): void
    {
        $now = CarbonImmutable::parse(self::NOW);

        [$from, $to] = DashboardPeriod::Today->range($now);
        $this->assertSame('2026-10-07 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-08 00:00:00', $to->toDateTimeString());

        [$from, $to] = DashboardPeriod::Last7Days->range($now);
        $this->assertSame('2026-10-01 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-08 00:00:00', $to->toDateTimeString());

        [$pFrom, $pTo] = DashboardPeriod::Last7Days->previousRange($now);
        $this->assertSame('2026-09-24 00:00:00', $pFrom->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $pTo->toDateTimeString());

        [$from, $to] = DashboardPeriod::LastMonth->range($now);
        $this->assertSame('2026-09-01 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $to->toDateTimeString());

        [$pFrom, $pTo] = DashboardPeriod::LastMonth->previousRange($now);
        $this->assertSame('2026-08-01 00:00:00', $pFrom->toDateTimeString());
        $this->assertSame('2026-09-01 00:00:00', $pTo->toDateTimeString());
    }

    #[Test]
    public function this_month_compares_against_the_same_number_of_days_of_last_month(): void
    {
        [$from, $to] = DashboardPeriod::ThisMonth->range(CarbonImmutable::parse(self::NOW));
        $this->assertSame('2026-10-01 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-08 00:00:00', $to->toDateTimeString());

        [$pFrom, $pTo] = DashboardPeriod::ThisMonth->previousRange(CarbonImmutable::parse(self::NOW));
        $this->assertSame('2026-09-01 00:00:00', $pFrom->toDateTimeString());
        $this->assertSame('2026-09-08 00:00:00', $pTo->toDateTimeString());
    }

    #[Test]
    public function this_month_previous_range_is_capped_at_the_end_of_a_shorter_last_month(): void
    {
        // ۳۱ اکتبر ⇒ ۳۱ روز؛ سپتامبر فقط ۳۰ روز دارد و سقف می‌خورد.
        [$pFrom, $pTo] = DashboardPeriod::ThisMonth->previousRange(CarbonImmutable::parse('2026-10-31 10:00:00'));

        $this->assertSame('2026-09-01 00:00:00', $pFrom->toDateTimeString());
        $this->assertSame('2026-10-01 00:00:00', $pTo->toDateTimeString());
    }

    #[Test]
    public function invalid_period_input_falls_back_to_the_default_instead_of_failing(): void
    {
        $this->assertSame(DashboardPeriod::Last30Days, DashboardPeriod::fromInput('bogus'));
        $this->assertSame(DashboardPeriod::Last30Days, DashboardPeriod::fromInput(null));
        $this->assertSame(DashboardPeriod::Last30Days, DashboardPeriod::fromInput(['x']));
        $this->assertSame(DashboardPeriod::Today, DashboardPeriod::fromInput('today'));
    }

    /* ------------------------------ KPI ------------------------------ */

    #[Test]
    public function summary_counts_only_settled_sales_of_this_reseller_within_the_period(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $this->sale($reseller, 100000, 70000);                                  // خرید: سود ۳۰٬۰۰۰
        $this->sale($reseller, 80000, 60000, 'paid');                           // خرید: سود ۲۰٬۰۰۰
        $this->sale($reseller, 50000, 40000, 'account_created', renewal: true); // تمدید: سود ۱۰٬۰۰۰

        // اینها فروش قطعی نیستند
        $this->sale($reseller, 900000, 100000, 'pending');
        $this->sale($reseller, 900000, 100000, 'failed');
        $this->sale($reseller, 900000, 100000, 'refunded');
        $this->sale($reseller, 900000, 100000, 'provision_failed');

        // خارج از بازه (نیمه‌باز: دقیقاً ۸ اکتبر ۰۰:۰۰ بیرون است، ۳۰ ام + ۱ روز قبل از ۸ سپتامبر)
        $this->sale($reseller, 900000, 100000, at: '2026-10-08 00:00:00');
        $this->sale($reseller, 900000, 100000, at: '2026-09-07 23:59:59');

        // نماینده‌ی دیگر و سفارش Main
        $this->sale($other, 900000, 100000);
        Order::factory()->create(['reseller_id' => null, 'main_price' => 900000, 'status' => 'account_created', 'created_at' => '2026-10-07 09:00:00']);

        $totals = $this->service()->summary($reseller, DashboardPeriod::Last30Days)->current;

        $this->assertSame(3, $totals->orders);
        $this->assertSame(1, $totals->renewals);
        $this->assertSame(2, $totals->purchases());
        $this->assertSame(230000, $totals->revenue);
        $this->assertSame(170000, $totals->cost);
        $this->assertSame(60000, $totals->profit);
        $this->assertSame(76666, $totals->averageOrder());
    }

    #[Test]
    public function profit_matches_the_per_order_model_formula(): void
    {
        $reseller = Reseller::factory()->create();
        $a = $this->sale($reseller, 100000, 70000);
        $b = $this->sale($reseller, 80000, 60000, 'paid');

        $summary = $this->service()->summary($reseller, DashboardPeriod::Today);

        $this->assertSame($a->resellerProfit() + $b->resellerProfit(), $summary->current->profit);
    }

    #[Test]
    public function an_empty_period_is_all_zero_and_has_no_comparison_base(): void
    {
        $reseller = Reseller::factory()->create();

        $summary = $this->service()->summary($reseller, DashboardPeriod::Last7Days);

        $this->assertSame(0, $summary->current->orders);
        $this->assertSame(0, $summary->current->revenue);
        $this->assertSame(0, $summary->current->averageOrder());
        $this->assertNull($summary->revenueChange());
    }

    #[Test]
    public function summary_compares_with_the_previous_equal_length_period(): void
    {
        $reseller = Reseller::factory()->create();

        $this->sale($reseller, 150000, 100000, at: '2026-10-07 10:00:00'); // امروز
        $this->sale($reseller, 100000, 80000, at: '2026-10-06 10:00:00');  // دیروز

        $summary = $this->service()->summary($reseller, DashboardPeriod::Today);

        $this->assertSame(150000, $summary->current->revenue);
        $this->assertSame(100000, $summary->previous->revenue);
        $this->assertSame(50, $summary->revenueChange());
        $this->assertSame(150, $summary->profitChange()); // ۵۰٬۰۰۰ در برابر ۲۰٬۰۰۰
    }

    #[Test]
    public function new_customers_count_memberships_created_in_the_period(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $old = $this->memberOf($reseller);
        $this->accountIn($old, $reseller)->forceFill(['created_at' => '2026-08-01 10:00:00'])->save();

        $this->memberOf($reseller);
        $this->memberOf($reseller);
        $this->memberOf($other);

        $totals = $this->service()->summary($reseller, DashboardPeriod::Last30Days)->current;

        $this->assertSame(2, $totals->newCustomers);
    }

    #[Test]
    public function percent_change_is_integer_rounded_signed_and_null_without_a_base(): void
    {
        $this->assertSame(50, ResellerSummary::percentChange(150, 100));
        $this->assertSame(-50, ResellerSummary::percentChange(50, 100));
        $this->assertSame(0, ResellerSummary::percentChange(100, 100));
        $this->assertSame(33, ResellerSummary::percentChange(4, 3)); // ۳۳٫۳۳ ⇒ ۳۳
        $this->assertSame(67, ResellerSummary::percentChange(5, 3)); // ۶۶٫۶۶ ⇒ ۶۷
        $this->assertSame(-100, ResellerSummary::percentChange(0, 100));
        $this->assertNull(ResellerSummary::percentChange(100, 0));
        $this->assertNull(ResellerSummary::percentChange(0, 0));
    }

    #[Test]
    public function percent_change_does_not_overflow_on_huge_amounts(): void
    {
        // PHP_INT_MAX نسبت به نصفش ≈ ۱۰۰٪ رشد است؛ بدون سرریزِ ×۱۰۰ و بدون تبدیل به float.
        $change = ResellerSummary::percentChange(PHP_INT_MAX, intdiv(PHP_INT_MAX, 2));
        $this->assertIsInt($change);
        $this->assertEqualsWithDelta(100, $change, 1);

        $this->assertIsInt(ResellerSummary::percentChange(PHP_INT_MAX, 1_000_000_000_000));
    }

    /* ------------------------------ وضعیت لحظه‌ای ------------------------------ */

    #[Test]
    public function position_never_creates_a_wallet(): void
    {
        $reseller = Reseller::factory()->create();
        $this->assertSame(0, Wallet::query()->where('user_id', $reseller->user_id)->count());

        $position = $this->service()->position($reseller);

        $this->assertSame(0, $position->balance);
        $this->assertSame(0, Wallet::query()->where('user_id', $reseller->user_id)->count());
    }

    #[Test]
    public function position_reads_the_resellers_main_wallet_balance_and_debt_headroom(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 50000]);

        // کیف‌پول همین کاربر در فروشگاهِ خودش نباید با اعتبار نماینده قاطی شود.
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 777777]);

        // ایندکس یکتای (user_id, scope_key) ردیف‌ها را به ترتیب scope_key برمی‌گرداند و «main» از «reseller:N»
        // الفبایی جلوتر است؛ پس بدون این ردیف، کوئریِ فراموش‌شده‌ی scope_key هم اتفاقاً درست جواب می‌داد.
        // این ردیف خام (بدون hook مدل) الفبایی قبل از «main» است و فقط کوئریِ واقعاً scope‌دار از آن عبور می‌کند.
        DB::table('wallets')->insert([
            'user_id' => $reseller->user_id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id,
            'scope_key' => 'a-not-main', 'balance' => 555555, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => -20000]);

        $position = $this->service()->position($reseller);

        $this->assertSame(-20000, $position->balance);
        $this->assertTrue($position->isInDebt());
        $this->assertSame(30000, $position->purchasingPower());
        $this->assertFalse($position->isOutOfCredit());
    }

    #[Test]
    public function a_reseller_at_the_debt_limit_is_out_of_credit(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 50000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => -50000]);

        $position = $this->service()->position($reseller);

        $this->assertSame(0, $position->purchasingPower());
        $this->assertTrue($position->isOutOfCredit());
    }

    #[Test]
    public function pending_payments_are_scoped_to_this_resellers_customer_charges(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();
        $method = PaymentMethod::factory()->create();
        $customer = $this->memberOf($reseller);

        $make = fn (array $a) => Payment::query()->create(array_merge([
            'user_id' => $customer->id, 'payment_method_id' => $method->id, 'amount' => 10000,
            'purpose' => 'wallet_charge', 'status' => 'pending', 'wallet_owner_type' => 'user', 'reseller_id' => $reseller->id,
        ], $a));

        $make([]);
        $make(['amount' => 5000]);
        $make(['status' => 'approved']);                         // تأییدشده
        $make(['wallet_owner_type' => 'reseller']);              // شارژ اعتبار خودِ نماینده (تأییدش با ادمین است)
        $make(['reseller_id' => $other->id]);                    // نماینده‌ی دیگر
        $make(['reseller_id' => null]);                          // Main

        $position = $this->service()->position($reseller);

        $this->assertSame(2, $position->pendingPayments);
        $this->assertSame(15000, $position->pendingPaymentsAmount);
    }

    #[Test]
    public function position_counts_attention_and_in_progress_orders_of_this_reseller_only(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $this->sale($reseller, 1000, 500, 'provision_failed');
        $this->sale($reseller, 1000, 500, 'provision_failed', at: '2026-01-01 10:00:00'); // قدیمی هم نیازمند رسیدگی است
        $this->sale($reseller, 1000, 500, 'provisioning');
        $this->sale($reseller, 1000, 500, 'paid');
        $this->sale($reseller, 1000, 500, 'account_created');
        $this->sale($other, 1000, 500, 'provision_failed');

        $position = $this->service()->position($reseller);

        $this->assertSame(2, $position->attentionOrders);
        $this->assertSame(2, $position->inProgressOrders);
    }

    #[Test]
    public function position_counts_customers_and_sold_services_but_not_test_accounts_or_other_stores(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $c1 = $this->memberOf($reseller);
        $c2 = $this->memberOf($reseller);
        $stranger = $this->memberOf($other);

        $make = fn ($user, $store, array $a = []) => Account::factory()->create(array_merge([
            'user_id' => $user->id, 'customer_account_id' => $this->accountIn($user, $store)->id,
        ], $a));

        $make($c1, $reseller, ['expires_at' => now()->addDays(3)]);              // فعال + رو‌به‌انقضا
        $make($c1, $reseller, ['expires_at' => now()->addDays(20)]);             // فعال
        $make($c2, $reseller, ['expires_at' => now()->addYear()]);               // فعال، انقضای دور
        $make($c2, $reseller, ['expires_at' => now()->subDay()]);                // منقضی
        $make($c2, $reseller, ['expires_at' => now()->addDays(2), 'is_test' => true]); // آزمایشی
        $make($stranger, $other, ['expires_at' => now()->addDays(1)]);           // نماینده‌ی دیگر

        $position = $this->service()->position($reseller);

        $this->assertSame(2, $position->totalCustomers);
        $this->assertSame(3, $position->activeServices);
        $this->assertSame(1, $position->expiringServices);
    }

    /* ------------------------------ روند ------------------------------ */

    #[Test]
    public function trend_has_every_day_of_the_period_with_zero_filled_gaps(): void
    {
        $reseller = Reseller::factory()->create();

        $this->sale($reseller, 100000, 70000, at: '2026-10-07 09:00:00');
        $this->sale($reseller, 50000, 30000, at: '2026-10-07 18:30:00');
        $this->sale($reseller, 80000, 60000, at: '2026-10-05 12:00:00');
        $this->sale($reseller, 900000, 100000, 'pending', at: '2026-10-06 12:00:00');

        $points = $this->service()->trend($reseller, DashboardPeriod::Last7Days);

        $this->assertCount(7, $points);
        $byDay = collect($points)->keyBy(fn ($p) => $p->day->format('Y-m-d'));

        $this->assertSame(['2026-10-01', '2026-10-07'], [$points[0]->day->format('Y-m-d'), $points[6]->day->format('Y-m-d')]);
        $this->assertSame(2, $byDay['2026-10-07']->orders);
        $this->assertSame(150000, $byDay['2026-10-07']->revenue);
        $this->assertSame(50000, $byDay['2026-10-07']->profit);
        $this->assertSame(80000, $byDay['2026-10-05']->revenue);
        $this->assertSame(0, $byDay['2026-10-06']->revenue);
        $this->assertSame(0, $byDay['2026-10-01']->orders);
        $this->assertSame(230000, collect($points)->sum('revenue'));
    }

    #[Test]
    public function trend_total_equals_summary_revenue(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 100000, 70000, at: '2026-09-20 09:00:00');
        $this->sale($reseller, 55000, 30000, at: '2026-10-02 09:00:00');

        $points = $this->service()->trend($reseller, DashboardPeriod::Last30Days);
        $summary = $this->service()->summary($reseller, DashboardPeriod::Last30Days);

        $this->assertCount(30, $points);
        $this->assertSame($summary->current->revenue, collect($points)->sum('revenue'));
        $this->assertSame($summary->current->profit, collect($points)->sum('profit'));
    }

    /* ------------------------------ آخرین سفارش‌ها ------------------------------ */

    #[Test]
    public function recent_orders_are_this_resellers_newest_first_and_limited(): void
    {
        $reseller = Reseller::factory()->create();
        $other = Reseller::factory()->create();

        $first = $this->sale($reseller, 1000, 500, 'pending');
        $second = $this->sale($reseller, 1000, 500, 'failed');
        $this->sale($other, 1000, 500);

        $orders = $this->service()->recentOrders($reseller, 2);

        $this->assertSame([$second->id, $first->id], $orders->pluck('id')->all());
        $this->assertCount(1, $this->service()->recentOrders($reseller, 1));
    }

    /* ------------------------------ هشدارها ------------------------------ */

    #[Test]
    public function alerts_are_empty_when_everything_is_fine(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => 500000]);

        $this->assertSame([], $this->service()->alerts($this->service()->position($reseller)));
    }

    #[Test]
    public function alerts_are_ordered_danger_then_warning_then_info(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 0]);
        $method = PaymentMethod::factory()->create();
        $customer = $this->memberOf($reseller);

        // balance=0 و سقف بدهی صفر ⇒ اعتبار تمام‌شده (danger)
        $this->sale($reseller, 1000, 500, 'provision_failed');          // danger
        Payment::query()->create([
            'user_id' => $customer->id, 'payment_method_id' => $method->id, 'amount' => 20000, 'purpose' => 'wallet_charge',
            'status' => 'pending', 'wallet_owner_type' => 'user', 'reseller_id' => $reseller->id,
        ]);                                                              // warning
        Account::factory()->create([
            'user_id' => $customer->id, 'customer_account_id' => $this->accountIn($customer, $reseller)->id,
            'expires_at' => now()->addDays(2),
        ]);                                                              // info

        $alerts = $this->service()->alerts($this->service()->position($reseller));

        $this->assertSame(
            ['out_of_credit', 'attention_orders', 'pending_payments', 'expiring_services'],
            array_map(fn (ResellerAlert $a) => $a->key, $alerts)
        );
        $this->assertSame(
            [ResellerAlert::TONE_DANGER, ResellerAlert::TONE_DANGER, ResellerAlert::TONE_WARNING, ResellerAlert::TONE_INFO],
            array_map(fn (ResellerAlert $a) => $a->tone, $alerts)
        );
        $this->assertSame(1, $alerts[1]->count);
    }

    #[Test]
    public function a_debt_within_the_limit_is_a_warning_not_out_of_credit(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => -30000]);

        $alerts = $this->service()->alerts($this->service()->position($reseller));

        $this->assertCount(1, $alerts);
        $this->assertSame('in_debt', $alerts[0]->key);
        $this->assertSame(ResellerAlert::TONE_WARNING, $alerts[0]->tone);
    }

    #[Test]
    public function core_alerts_carry_raw_integer_amounts_and_the_channel_formats_them(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        Wallet::query()->create(['user_id' => $reseller->user_id, 'store_type' => 'main', 'balance' => -30000]);

        $alert = $this->service()->alerts($this->service()->position($reseller))[0];

        $this->assertSame(['debt' => 30000, 'power' => 70000], $alert->amounts);
        $this->assertStringContainsString('{debt}', $alert->body);
        $this->assertSame(
            'بدهی فعلی: <30000> — هنوز <70000> تا سقف بدهی مجاز باقی مانده است.',
            $alert->renderBody(fn (int $minor) => '<'.$minor.'>'),
        );
    }

    /* ------------------------------ کارایی / فقط‌خواندنی ------------------------------ */

    #[Test]
    public function query_count_does_not_depend_on_the_number_of_orders(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 1000, 500);

        $measure = function () use ($reseller): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->service()->summary($reseller, DashboardPeriod::Last30Days);
            $this->service()->position($reseller);
            $this->service()->trend($reseller, DashboardPeriod::Last30Days);
            $this->service()->recentOrders($reseller);

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $few = $measure();

        for ($i = 0; $i < 15; $i++) {
            $this->sale($reseller, 1000 + $i, 500);
        }

        $this->assertSame($few, $measure());
    }

    #[Test]
    public function the_service_writes_nothing(): void
    {
        $reseller = Reseller::factory()->create();
        $this->sale($reseller, 1000, 500);

        $before = [Wallet::count(), Order::count(), Payment::count(), Account::count()];

        $this->service()->summary($reseller, DashboardPeriod::Last30Days);
        $position = $this->service()->position($reseller);
        $this->service()->trend($reseller, DashboardPeriod::Today);
        $this->service()->recentOrders($reseller);
        $this->service()->alerts($position);

        $this->assertSame($before, [Wallet::count(), Order::count(), Payment::count(), Account::count()]);
    }
}
