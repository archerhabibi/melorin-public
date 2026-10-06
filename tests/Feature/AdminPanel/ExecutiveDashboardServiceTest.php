<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\Dashboard\ExecutiveAlert;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Services\Admin\Dashboard\ExecutiveTotals;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\PercentChange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B7.1 — سرویس داشبورد اجرایی ادمین (Core). فقط‌خواندنی، Aggregate در DB، همه‌ی مبالغ int.
 */
class ExecutiveDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    /** چهارشنبه ۷ اکتبر ۲۰۲۶، ظهر */
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

    private function service(): ExecutiveDashboardService
    {
        return app(ExecutiveDashboardService::class);
    }

    /** فروش مستقیم (فروشگاه اصلی): فقط main_price پر است */
    private function direct(int $price, string $status = 'account_created', string $at = '2026-10-07 09:00:00', string $channel = 'website', bool $renewal = false): Order
    {
        return Order::factory()->create([
            'sales_channel' => $channel,
            'main_price' => $price,
            'reseller_price' => null,
            'customers_price' => null,
            'status' => $status,
            'renews_account_id' => $renewal ? 999 : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** فروش نمایندگی: $price = customers_price، $cost = reseller_price */
    private function resell(Reseller $reseller, int $price, int $cost, string $status = 'account_created', string $at = '2026-10-07 09:00:00'): Order
    {
        return Order::factory()->create([
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'main_price' => null,
            'reseller_price' => $cost,
            'customers_price' => $price,
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function pending(array $attributes = [], string $at = '2026-10-07 11:00:00'): Payment
    {
        $payment = Payment::query()->create($attributes + [
            'user_id' => User::factory()->create()->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'amount' => 50000,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
            'wallet_owner_type' => 'user',
            'reseller_id' => null,
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $payment;
    }

    private function mainWallet(User|int $user, int $balance): Wallet
    {
        return Wallet::query()->create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'store_type' => 'main',
            'balance' => $balance,
        ]);
    }

    private function totals(DashboardPeriod $period = DashboardPeriod::Last30Days): ExecutiveTotals
    {
        return $this->service()->summary($period)->current;
    }

    /* ------------------------------ تعریف‌ها ------------------------------ */

    #[Test]
    public function sales_platform_revenue_and_reseller_margin_follow_the_contract_definitions(): void
    {
        $reseller = Reseller::factory()->create();

        $this->direct(100000);
        $this->resell($reseller, 150000, 120000);

        $totals = $this->totals();

        $this->assertSame(2, $totals->orders);
        $this->assertSame(250000, $totals->sales);            // آنچه مشتریان پرداخته‌اند
        $this->assertSame(220000, $totals->platformRevenue);  // 100000 مستقیم + 120000 قیمت تأمین
        $this->assertSame(30000, $totals->resellerMargin());  // سود نماینده، نه درآمد ما
        $this->assertSame(100000, $totals->directSales);
        $this->assertSame(150000, $totals->resellerSales);
        $this->assertSame(1, $totals->resellerOrders);
        $this->assertSame(125000, $totals->averageOrder());
        $this->assertSame(60, $totals->resellerSharePercent());
    }

    #[Test]
    public function only_settled_orders_count_as_sales(): void
    {
        $this->direct(100000, 'account_created');
        $this->direct(70000, 'paid');

        foreach (['pending', 'failed', 'refunded', 'provision_failed', 'provisioning'] as $status) {
            $this->direct(900000, $status);
        }

        $totals = $this->totals();

        $this->assertSame(2, $totals->orders);
        $this->assertSame(170000, $totals->sales);
    }

    #[Test]
    public function test_account_orders_are_never_counted(): void
    {
        $this->direct(100000);
        $this->direct(5000, 'account_created', channel: 'test_account');
        $this->direct(5000, 'provision_failed', channel: 'test_account');

        $totals = $this->totals();

        $this->assertSame(1, $totals->orders);
        $this->assertSame(100000, $totals->sales);
        $this->assertSame(0, $totals->failedOrders);
        $this->assertSame(0, $this->service()->position()->attentionOrders);
        $this->assertCount(1, $this->service()->recentOrders());
    }

    #[Test]
    public function renewals_are_separated_from_purchases(): void
    {
        $this->direct(100000);
        $this->direct(100000, renewal: true);
        $this->direct(100000, renewal: true);

        $totals = $this->totals();

        $this->assertSame(3, $totals->orders);
        $this->assertSame(2, $totals->renewals);
        $this->assertSame(1, $totals->purchases());
    }

    #[Test]
    public function failed_orders_and_the_failure_rate_are_integer_per_mille(): void
    {
        $this->direct(100000);
        $this->direct(100000);
        $this->direct(100000, 'failed');
        $this->direct(100000, 'provision_failed');

        $totals = $this->totals();

        $this->assertSame(2, $totals->failedOrders);
        $this->assertSame(500, $totals->failureRatePerMille()); // ۲ از ۴ = ۵۰٫۰٪
        $this->assertSame(0, ExecutiveTotals::empty()->failureRatePerMille());

        // گرد به نزدیک‌ترین: ۱ از ۳ = ۳۳۳٫۳ ⇒ ۳۳۳؛ ۲ از ۳ = ۶۶۶٫۶ ⇒ ۶۶۷
        $one = new ExecutiveTotals(2, 0, 0, 0, 0, 0, 0, 1, 0, 0);
        $two = new ExecutiveTotals(1, 0, 0, 0, 0, 0, 0, 2, 0, 0);
        $this->assertSame(333, $one->failureRatePerMille());
        $this->assertSame(667, $two->failureRatePerMille());
    }

    #[Test]
    public function new_users_and_services_are_counted_in_the_period_and_test_services_are_excluded(): void
    {
        // Factory حساب‌ها خودش کاربر/سفارش می‌سازد؛ آن‌ها را از بازه بیرون می‌بریم تا فقط کاربران زیر شمرده شوند.
        Account::factory()->create(['is_test' => false, 'created_at' => '2026-10-07 08:00:00']);
        Account::factory()->create(['is_test' => true, 'created_at' => '2026-10-07 08:00:00']);
        Account::factory()->create(['is_test' => false, 'created_at' => '2026-07-01 08:00:00']);
        DB::table('users')->update(['created_at' => '2026-01-01 00:00:00']);

        User::factory()->create(['created_at' => '2026-10-06 10:00:00']);
        User::factory()->create(['created_at' => '2026-10-07 10:00:00']);
        User::factory()->create(['created_at' => '2026-08-01 10:00:00']);

        $totals = $this->totals(DashboardPeriod::Last7Days);

        $this->assertSame(2, $totals->newUsers);
        $this->assertSame(1, $totals->newServices);
    }

    /* ------------------------------ بازه و مقایسه ------------------------------ */

    #[Test]
    public function the_period_is_half_open_and_compares_with_the_previous_equal_length_range(): void
    {
        // امروز [۷ اکتبر ۰۰:۰۰، ۸ اکتبر ۰۰:۰۰) — دیروز بازه‌ی قبلی
        $this->direct(300000, at: '2026-10-07 00:00:00'); // دقیقاً مرز شروع ⇒ داخل
        $this->direct(111000, at: '2026-10-06 23:59:59'); // دیروز
        $this->direct(100000, at: '2026-10-06 00:00:00'); // دیروز (مرز شروعِ بازه‌ی قبلی)
        $this->direct(999000, at: '2026-10-05 23:59:59'); // پیش از هر دو بازه

        $summary = $this->service()->summary(DashboardPeriod::Today);

        $this->assertSame(300000, $summary->current->sales);
        $this->assertSame(211000, $summary->previous->sales);
        $this->assertSame(PercentChange::of(300000, 211000), $summary->salesChange());
        $this->assertSame(42, $summary->salesChange());
    }

    #[Test]
    public function an_empty_previous_period_has_no_comparison_basis(): void
    {
        $this->direct(100000);

        $summary = $this->service()->summary(DashboardPeriod::Today);

        $this->assertNull($summary->salesChange());
        $this->assertNull($summary->ordersChange());
        $this->assertNull(PercentChange::of(5, 0));
        $this->assertSame(0, PercentChange::of(100, 100));
        $this->assertSame(-50, PercentChange::of(50, 100));
        $this->assertSame(67, PercentChange::of(5, 3));
    }

    /* ------------------------------ روند ------------------------------ */

    #[Test]
    public function the_trend_has_every_day_and_sums_to_the_summary(): void
    {
        $reseller = Reseller::factory()->create();

        $this->direct(100000, at: '2026-10-07 10:00:00');
        $this->resell($reseller, 150000, 120000, at: '2026-10-05 10:00:00');
        $this->direct(70000, at: '2026-09-20 10:00:00');

        $points = $this->service()->trend(DashboardPeriod::Last7Days);
        $summary = $this->service()->summary(DashboardPeriod::Last7Days)->current;

        $this->assertCount(7, $points);
        $this->assertSame('2026-10-07', $points[6]->day->toDateString());
        $this->assertSame(100000, $points[6]->sales);
        $this->assertSame(150000, $points[4]->sales);
        $this->assertSame(120000, $points[4]->platformRevenue);
        $this->assertSame(0, $points[0]->sales);
        $this->assertSame($summary->sales, array_sum(array_map(fn ($p) => $p->sales, $points)));
        $this->assertSame($summary->platformRevenue, array_sum(array_map(fn ($p) => $p->platformRevenue, $points)));
        $this->assertSame($summary->orders, array_sum(array_map(fn ($p) => $p->orders, $points)));
    }

    /* ------------------------------ وضعیت لحظه‌ای ------------------------------ */

    #[Test]
    public function the_platform_payment_queue_excludes_receipts_that_belong_to_a_resellers_queue(): void
    {
        $reseller = Reseller::factory()->create();

        $this->pending(['amount' => 10000]);                                                    // فروشگاه اصلی ⇒ صف ما
        $this->pending(['amount' => 20000, 'wallet_owner_type' => 'reseller', 'reseller_id' => $reseller->id]); // اعتبار نماینده ⇒ صف ما
        $this->pending(['amount' => 90000, 'wallet_owner_type' => 'user', 'reseller_id' => $reseller->id]);     // مشتری نماینده ⇒ صف نماینده
        $this->pending(['amount' => 70000, 'status' => 'confirmed']);                           // تمام‌شده

        $position = $this->service()->position();

        $this->assertSame(2, $position->pendingPayments);
        $this->assertSame(30000, $position->pendingPaymentsAmount);
        $this->assertSame(1, $this->service()->resellerQueuePendingPayments());
    }

    #[Test]
    public function stale_payments_are_those_waiting_more_than_the_threshold(): void
    {
        $this->pending(at: '2026-10-07 11:00:00');   // ۱ ساعت
        $this->pending(at: '2026-10-06 13:00:00');   // ۲۳ ساعت
        $this->pending(at: '2026-10-06 12:00:00');   // دقیقاً ۲۴ ساعت ⇒ کهنه
        $this->pending(at: '2026-10-01 12:00:00');   // ۶ روز

        $position = $this->service()->position();

        $this->assertSame(4, $position->pendingPayments);
        $this->assertSame(2, $position->stalePayments);
    }

    #[Test]
    public function orders_in_progress_and_needing_attention_are_counted_separately(): void
    {
        $this->direct(1000, 'provision_failed', at: '2026-01-01 10:00:00'); // همه‌ی زمان‌ها
        $this->direct(1000, 'provision_failed');
        $this->direct(1000, 'paid');
        $this->direct(1000, 'provisioning');
        $this->direct(1000, 'account_created');

        $position = $this->service()->position();

        $this->assertSame(2, $position->attentionOrders);
        $this->assertSame(2, $position->inProgressOrders);
    }

    #[Test]
    public function servers_are_aggregated_over_active_servers_only(): void
    {
        ServerPanel::factory()->create(['health_status' => 'healthy', 'capacity' => 100, 'active_accounts_count' => 40]);
        ServerPanel::factory()->create(['health_status' => 'degraded', 'capacity' => 100, 'active_accounts_count' => 60]);
        ServerPanel::factory()->create(['health_status' => 'down', 'capacity' => 50, 'active_accounts_count' => 50]);
        ServerPanel::factory()->create(['health_status' => null, 'capacity' => null, 'active_accounts_count' => 77]);
        ServerPanel::factory()->create(['status' => 'inactive', 'health_status' => 'down', 'capacity' => 10, 'active_accounts_count' => 10]);

        $position = $this->service()->position();

        $this->assertSame(4, $position->activeServers);
        $this->assertSame(1, $position->serversDown);
        $this->assertSame(1, $position->serversDegraded);
        $this->assertSame(1, $position->serversFull);
        $this->assertSame(250, $position->capacityTotal);
        $this->assertSame(150, $position->capacityUsed);   // سرور بی‌ظرفیت در مصرف نمی‌آید
        $this->assertSame(60, $position->capacityUsagePercent());
    }

    #[Test]
    public function capacity_usage_is_null_when_no_server_defines_a_capacity(): void
    {
        ServerPanel::factory()->create(['capacity' => null]);

        $this->assertNull($this->service()->position()->capacityUsagePercent());
    }

    #[Test]
    public function tickets_users_services_and_resellers_are_counted(): void
    {
        Ticket::factory()->create(['status' => 'open', 'priority' => 'high']);
        Ticket::factory()->create(['status' => 'open', 'priority' => 'normal']);
        Ticket::factory()->create(['status' => 'answered', 'priority' => 'high']);
        Ticket::factory()->create(['status' => 'closed']);

        User::factory()->create(['status' => 'active']);
        User::factory()->create(['status' => 'blocked']);

        Reseller::factory()->create(['status' => 'active']);
        Reseller::factory()->create(['status' => 'inactive']);

        Account::factory()->create(['is_test' => false, 'expires_at' => now()->addDays(3)]);   // رو‌به‌انقضا
        Account::factory()->create(['is_test' => false, 'expires_at' => now()->addDays(20)]);
        Account::factory()->create(['is_test' => false, 'expires_at' => now()->subDay()]);      // منقضی
        Account::factory()->create(['is_test' => true, 'expires_at' => now()->addDays(2)]);    // آزمایشی

        $position = $this->service()->position();

        $this->assertSame(2, $position->openTickets);
        $this->assertSame(1, $position->openHighPriorityTickets);
        $this->assertSame(1, $position->activeResellers);
        $this->assertSame(2, $position->activeServices);
        $this->assertSame(1, $position->expiringServices);
        $this->assertGreaterThanOrEqual(1, $position->activeUsers);
    }

    #[Test]
    public function financial_obligations_are_read_from_main_wallets_only(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $reseller = Reseller::factory()->create(['debt_limit' => 100000]);
        $inactive = Reseller::factory()->create(['status' => 'inactive', 'debt_limit' => 0]);

        $this->mainWallet($customer, 300000);
        $this->mainWallet($other, 200000);
        $this->mainWallet($reseller->user_id, -80000);          // بدهی نماینده (در سقف)
        $this->mainWallet($inactive->user_id, -20000);          // نماینده‌ی غیرفعال: بدهی هست، «بدون اعتبار» نیست
        // Wallet فروشگاه نماینده ⇒ تعهد خود نماینده است، نه پلتفرم
        Wallet::query()->create(['user_id' => $customer->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'balance' => 999999]);

        $position = $this->service()->position();

        $this->assertSame(500000, $position->prepaidBalance);
        $this->assertSame(100000, $position->resellerDebt);
        $this->assertSame(0, $position->resellersOutOfCredit); // -80000 + 100000 > 0
    }

    #[Test]
    public function a_reseller_is_out_of_credit_only_with_a_wallet_and_no_buying_power(): void
    {
        $broke = Reseller::factory()->create(['debt_limit' => 50000]);
        $exactlyZero = Reseller::factory()->create(['debt_limit' => 0]);
        $fine = Reseller::factory()->create(['debt_limit' => 50000]);
        Reseller::factory()->create(['debt_limit' => 0]); // بدون Wallet ⇒ شمرده نمی‌شود

        $this->mainWallet($broke->user_id, -50000);   // ۰ قدرت خرید
        $this->mainWallet($exactlyZero->user_id, 0);  // ۰ قدرت خرید
        $this->mainWallet($fine->user_id, -10000);    // ۴۰۰۰۰ قدرت خرید

        $this->assertSame(2, $this->service()->position()->resellersOutOfCredit);
    }

    #[Test]
    public function reading_never_writes_anything(): void
    {
        Reseller::factory()->create();

        $before = [
            Wallet::query()->count(),
            Order::query()->count(),
            Payment::query()->count(),
            DB::table('audit_logs')->count(),
        ];

        $service = $this->service();
        $position = $service->position();
        $service->summary(DashboardPeriod::Last30Days);
        $service->trend(DashboardPeriod::Last30Days);
        $service->topResellers(DashboardPeriod::Last30Days);
        $service->alerts($position);
        $service->recentOrders();
        $service->serverHealthQuery()->get();
        $service->resellerQueuePendingPayments();

        $this->assertSame($before, [
            Wallet::query()->count(),
            Order::query()->count(),
            Payment::query()->count(),
            DB::table('audit_logs')->count(),
        ]);
    }

    /* ------------------------------ نیازمند توجه ------------------------------ */

    #[Test]
    public function a_clean_platform_has_no_alerts(): void
    {
        ServerPanel::factory()->create(['health_status' => 'healthy', 'capacity' => 100, 'active_accounts_count' => 10]);

        $this->assertSame([], $this->service()->alerts($this->service()->position()));
    }

    #[Test]
    public function alerts_are_ordered_danger_then_warning_then_info_with_targets(): void
    {
        $reseller = Reseller::factory()->create(['debt_limit' => 0]);
        $this->mainWallet($reseller->user_id, -5000);

        $this->direct(1000, 'provision_failed');
        $this->pending();
        ServerPanel::factory()->create(['health_status' => 'down', 'capacity' => 10, 'active_accounts_count' => 1]);
        ServerPanel::factory()->create(['health_status' => 'degraded', 'capacity' => 10, 'active_accounts_count' => 1]);
        ServerPanel::factory()->create(['health_status' => 'healthy', 'capacity' => 5, 'active_accounts_count' => 5]);
        Ticket::factory()->create(['status' => 'open', 'priority' => 'normal']);

        $alerts = $this->service()->alerts($this->service()->position());
        $keys = array_map(fn (ExecutiveAlert $a) => $a->key, $alerts);

        $this->assertSame([
            'attention_orders', 'servers_down',
            'pending_payments', 'resellers_out_of_credit', 'servers_degraded', 'servers_full',
            'open_tickets',
        ], $keys);

        $byKey = collect($alerts)->keyBy('key');
        $this->assertSame(ExecutiveAlert::TARGET_ATTENTION_ORDERS, $byKey['attention_orders']->target);
        $this->assertSame(ExecutiveAlert::TARGET_PAYMENTS, $byKey['pending_payments']->target);
        $this->assertSame(ExecutiveAlert::TARGET_SERVERS, $byKey['servers_down']->target);
        $this->assertSame(ExecutiveAlert::TARGET_RESELLERS, $byKey['resellers_out_of_credit']->target);
        $this->assertSame(ExecutiveAlert::TARGET_TICKETS, $byKey['open_tickets']->target);
        $this->assertSame(ExecutiveAlert::TONE_INFO, $byKey['open_tickets']->tone);
    }

    #[Test]
    public function a_stale_receipt_escalates_the_payments_alert_and_high_priority_tickets_escalate_the_ticket_alert(): void
    {
        $this->pending(at: '2026-10-07 11:00:00');
        Ticket::factory()->create(['status' => 'open', 'priority' => 'high']);

        $byKey = collect($this->service()->alerts($this->service()->position()))->keyBy('key');
        $this->assertSame(ExecutiveAlert::TONE_WARNING, $byKey['pending_payments']->tone);
        $this->assertSame(ExecutiveAlert::TONE_WARNING, $byKey['open_tickets']->tone);

        $this->pending(at: '2026-10-01 11:00:00');

        $byKey = collect($this->service()->alerts($this->service()->position()))->keyBy('key');
        $this->assertSame(ExecutiveAlert::TONE_DANGER, $byKey['pending_payments']->tone);
    }

    #[Test]
    public function money_in_alert_bodies_is_raw_integers_and_formatted_only_by_the_channel(): void
    {
        $this->pending(['amount' => 123456]);

        $alert = collect($this->service()->alerts($this->service()->position()))->firstWhere('key', 'pending_payments');

        $this->assertSame(['pending' => 123456], $alert->amounts);
        $this->assertStringContainsString('{pending}', $alert->body);
        $this->assertStringContainsString('X123456X', $alert->renderBody(fn (int $minor) => 'X'.$minor.'X'));
        $this->assertStringNotContainsString('{pending}', $alert->renderBody(fn (int $minor) => (string) $minor));
    }

    /* ------------------------------ برترین نمایندگان / لیست‌ها ------------------------------ */

    #[Test]
    public function top_resellers_are_ranked_by_platform_revenue_within_the_period(): void
    {
        $small = Reseller::factory()->create(['slug' => 'small']);
        $big = Reseller::factory()->create(['slug' => 'big']);
        $old = Reseller::factory()->create(['slug' => 'old']);

        $this->resell($small, 60000, 50000);
        $this->resell($big, 150000, 120000);
        $this->resell($big, 150000, 120000);
        $this->resell($old, 900000, 800000, at: '2026-05-01 10:00:00'); // خارج از بازه
        $this->resell($big, 150000, 120000, status: 'failed');         // فروش نیست
        $this->direct(500000);                                          // فروش مستقیم نماینده ندارد

        $rows = $this->service()->topResellers(DashboardPeriod::Last30Days);

        $this->assertCount(2, $rows);
        $this->assertSame('big', $rows[0]->slug);
        $this->assertSame(2, $rows[0]->orders);
        $this->assertSame(300000, $rows[0]->sales);
        $this->assertSame(240000, $rows[0]->platformRevenue);
        $this->assertSame(60000, $rows[0]->margin());
        $this->assertSame('small', $rows[1]->slug);
        $this->assertCount(1, $this->service()->topResellers(DashboardPeriod::Last30Days, 1));
    }

    #[Test]
    public function recent_orders_are_latest_first_capped_and_span_every_store(): void
    {
        $reseller = Reseller::factory()->create();

        $first = $this->direct(1000);
        $second = $this->resell($reseller, 2000, 1500, 'pending');
        $third = $this->direct(3000, 'failed');

        $ids = $this->service()->recentOrders()->pluck('id')->all();

        $this->assertSame([$third->id, $second->id, $first->id], $ids);
        $this->assertCount(2, $this->service()->recentOrders(2));
        $this->assertCount(1, $this->service()->recentOrders(0)); // حداقل ۱
    }

    #[Test]
    public function the_server_list_puts_down_then_degraded_then_the_fullest_first(): void
    {
        $healthyLow = ServerPanel::factory()->create(['name' => 'healthy-low', 'health_status' => 'healthy', 'capacity' => 100, 'active_accounts_count' => 10]);
        $healthyHigh = ServerPanel::factory()->create(['name' => 'healthy-high', 'health_status' => 'healthy', 'capacity' => 100, 'active_accounts_count' => 90]);
        $degraded = ServerPanel::factory()->create(['name' => 'degraded', 'health_status' => 'degraded', 'capacity' => 100, 'active_accounts_count' => 5]);
        $down = ServerPanel::factory()->create(['name' => 'down', 'health_status' => 'down', 'capacity' => 100, 'active_accounts_count' => 1]);
        ServerPanel::factory()->create(['name' => 'off', 'status' => 'inactive', 'health_status' => 'down']);

        $names = $this->service()->serverHealthQuery()->pluck('name')->all();

        $this->assertSame(['down', 'degraded', 'healthy-high', 'healthy-low'], $names);
        $this->assertCount(2, $this->service()->serverHealthQuery(2)->get());
    }

    /* ------------------------------ کارایی ------------------------------ */

    #[Test]
    public function the_number_of_queries_does_not_depend_on_the_number_of_orders(): void
    {
        $reseller = Reseller::factory()->create();
        $this->direct(1000);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $service = $this->service();
            $service->summary(DashboardPeriod::Last30Days);
            $service->position();
            $service->trend(DashboardPeriod::Last30Days);
            $service->topResellers(DashboardPeriod::Last30Days);

            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count();

        for ($i = 0; $i < 15; $i++) {
            $this->direct(1000);
            $this->resell($reseller, 2000, 1500);
        }

        $this->assertSame($few, $count());
    }

    /* ------------------------------ مرز معماری ------------------------------ */

    #[Test]
    public function the_core_service_has_no_write_verbs_and_knows_nothing_about_the_channel(): void
    {
        $forbiddenWrites = ['->insert(', '->update(', '->delete(', '->truncate(', '->upsert(', 'DB::statement', 'DB::unprepared',
            '->save(', '->forceFill(', '->create(', 'Order::create(', 'Wallet::create(', 'Payment::create(', 'AuditLog::create(', '->increment(', '->decrement(', 'firstOrCreate', 'updateOrCreate'];
        $forbiddenChannel = ['Filament', 'route(', 'url(', 'Money::', 'Livewire', 'request('];

        foreach (glob(app_path('Services/Admin/Dashboard/*.php')) as $file) {
            $src = file_get_contents($file);

            foreach (array_merge($forbiddenWrites, $forbiddenChannel) as $needle) {
                // کامنت‌ها (توضیح فارسی) مجازند؛ فقط کد بررسی می‌شود
                $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
                $this->assertStringNotContainsString($needle, $code, basename($file)." must stay read-only/channel-free ({$needle})");
            }
        }
    }
}
