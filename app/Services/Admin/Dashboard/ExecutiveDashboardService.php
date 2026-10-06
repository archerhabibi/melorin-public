<?php

namespace App\Services\Admin\Dashboard;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * داشبورد اجرایی ادمین (B7.1): فروش، درآمد پلتفرم، سفارش‌ها، سرویس‌ها، سرورها و تعهدات مالی —
 * از یک منبع واحد در Core. هم‌خانواده‌ی `ResellerDashboardService` (B5.1) ولی برای کل پلتفرم.
 *
 * قواعد:
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد و هیچ‌چیز را تغییر نمی‌دهد. (باز کردن یک صفحه نباید در DB بنویسد.)
 *  - ورودی فقط بازه‌ی زمانی است؛ هیچ شناسه‌ای از Request خوانده نمی‌شود.
 *  - Aggregate در دیتابیس (SUM/COUNT/GROUP BY)؛ تعداد کوئری به تعداد سفارش‌ها وابسته نیست.
 *  - همه‌ی مبالغ int (Minor Unit) و بدون float؛ قالب‌بندی (Money::format) فقط در Channel.
 *  - منطق نمایش (URL، رنگ، Livewire) این‌جا نیست.
 *
 * تعریف «فروش قطعی» همان تعریف Reports ادمین و داشبورد نماینده است (paid و account_created)، با یک تفاوت
 * آگاهانه: سفارش‌های اکانت تست (`sales_channel=test_account`) در هیچ شمارشی نمی‌آیند (قیمتشان صفر است و
 * «تعداد سفارش» را بی‌دلیل بالا می‌برند). تعریف‌های کامل در ADMIN-EXECUTIVE-DASHBOARD-CONTRACT.md.
 */
class ExecutiveDashboardService
{
    /** وضعیت‌هایی که «فروش قطعی» حساب می‌شوند (هم‌تعریف با Reports ادمین) */
    public const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED];

    /** وضعیت‌های «سفارش ناموفق» */
    public const FAILED_STATUSES = [Order::STATUS_FAILED, Order::STATUS_PROVISION_FAILED];

    /** «در حال تحویل»: پول گرفته شده و سرویس در راه است */
    public const IN_PROGRESS_STATUSES = [Order::STATUS_PAID, Order::STATUS_PROVISIONING];

    /** «نزدیک انقضا»: انقضا در این تعداد روز آینده */
    public const EXPIRING_DAYS = 7;

    /** رسیدِ در انتظارِ بیش از این ساعت «کهنه» است */
    public const STALE_PAYMENT_HOURS = 24;

    public const RECENT_ORDERS_LIMIT = 8;

    public const SERVER_ROWS_LIMIT = 8;

    public const TOP_RESELLERS_LIMIT = 5;

    /** KPIهای بازه + مقایسه با بازه‌ی قبلِ هم‌طول */
    public function summary(DashboardPeriod $period, ?CarbonInterface $now = null): ExecutiveSummary
    {
        $now ??= now();

        [$from, $to] = $period->range($now);
        [$previousFrom, $previousTo] = $period->previousRange($now);

        return new ExecutiveSummary(
            $period,
            $from,
            $to,
            $this->totals($from, $to),
            $this->totals($previousFrom, $previousTo),
        );
    }

    /** وضعیت لحظه‌ای (مستقل از بازه): صف رسیدگی، سرویس‌ها، سرورها، تعهدات مالی */
    public function position(?CarbonInterface $now = null): ExecutivePosition
    {
        $now = CarbonImmutable::instance($now ?? now());

        $platformPayments = $this->platformPendingPaymentsQuery()
            ->selectRaw('count(*) as n, coalesce(sum(amount), 0) as total')
            ->selectRaw('coalesce(sum(case when created_at <= ? then 1 else 0 end), 0) as stale', [
                $now->subHours(self::STALE_PAYMENT_HOURS),
            ])
            ->first();

        $orderCounts = $this->realOrdersQuery()
            ->whereIn('orders.status', [Order::STATUS_PROVISION_FAILED, ...self::IN_PROGRESS_STATUSES])
            ->selectRaw('orders.status as status, count(*) as n')
            ->groupBy('orders.status')
            ->pluck('n', 'status');

        $tickets = Ticket::query()
            ->where('status', Ticket::STATUS_OPEN)
            ->selectRaw('count(*) as n')
            ->selectRaw("coalesce(sum(case when priority = 'high' then 1 else 0 end), 0) as high")
            ->first();

        $servers = ServerPanel::query()
            ->where('status', 'active')
            ->selectRaw('count(*) as n')
            ->selectRaw("coalesce(sum(case when health_status = 'down' then 1 else 0 end), 0) as down")
            ->selectRaw("coalesce(sum(case when health_status = 'degraded' then 1 else 0 end), 0) as degraded")
            ->selectRaw('coalesce(sum(case when capacity is not null and active_accounts_count >= capacity then 1 else 0 end), 0) as full')
            ->selectRaw('coalesce(sum(case when capacity is not null then capacity else 0 end), 0) as capacity_total')
            ->selectRaw('coalesce(sum(case when capacity is not null then active_accounts_count else 0 end), 0) as capacity_used')
            ->first();

        // سرویس‌های آزمایشی (is_test) فروش نیستند؛ در شمارش سرویس‌ها نمی‌آیند.
        $services = fn (): Builder => Account::query()->where('is_test', false)->activeNow();

        $inProgress = (int) collect(self::IN_PROGRESS_STATUSES)->sum(fn (string $s) => (int) ($orderCounts[$s] ?? 0));

        return new ExecutivePosition(
            activeUsers: User::query()->where('status', 'active')->count(),
            activeServices: $services()->count(),
            expiringServices: $services()
                ->where('expires_at', '<=', $now->addDays(self::EXPIRING_DAYS))
                ->count(),
            activeResellers: Reseller::query()->where('status', 'active')->count(),
            pendingPayments: (int) ($platformPayments->n ?? 0),
            pendingPaymentsAmount: (int) ($platformPayments->total ?? 0),
            stalePayments: (int) ($platformPayments->stale ?? 0),
            attentionOrders: (int) ($orderCounts[Order::STATUS_PROVISION_FAILED] ?? 0),
            inProgressOrders: $inProgress,
            openTickets: (int) ($tickets->n ?? 0),
            openHighPriorityTickets: (int) ($tickets->high ?? 0),
            activeServers: (int) ($servers->n ?? 0),
            serversDown: (int) ($servers->down ?? 0),
            serversDegraded: (int) ($servers->degraded ?? 0),
            serversFull: (int) ($servers->full ?? 0),
            capacityTotal: (int) ($servers->capacity_total ?? 0),
            capacityUsed: (int) ($servers->capacity_used ?? 0),
            prepaidBalance: $this->prepaidBalance(),
            resellerDebt: $this->resellerDebt(),
            resellersOutOfCredit: $this->resellersOutOfCredit(),
        );
    }

    /**
     * رسیدهای در انتظارِ «صف نمایندگان» (شارژ کیف‌پول مشتریِ یک نماینده؛ تأییدش با خود نماینده است).
     * این‌ها کارِ ادمین نیستند و در `pendingPayments` نمی‌آیند؛ فقط برای اطلاع شمرده می‌شوند.
     */
    public function resellerQueuePendingPayments(): int
    {
        return Payment::query()
            ->where('status', 'pending')
            ->whereNotNull('reseller_id')
            ->where('wallet_owner_type', 'user')
            ->count();
    }

    /**
     * روند روزانه‌ی فروش در بازه؛ همه‌ی روزها هستند (روز بدون فروش = صفر).
     *
     * @return list<ExecutiveTrendPoint>
     */
    public function trend(DashboardPeriod $period, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $period->range($now ?? now());

        $byDay = $this->settledOrdersQuery($from, $to)
            ->selectRaw('date(orders.created_at) as day')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.customers_price, 0)), 0) as sales')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.reseller_price, 0)), 0) as platform')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $points = [];

        foreach (CarbonPeriod::create($from, '1 day', $to->subSecond()) as $day) {
            $row = $byDay->get($day->format('Y-m-d'));

            $points[] = new ExecutiveTrendPoint(
                CarbonImmutable::instance($day)->startOfDay(),
                (int) ($row->orders ?? 0),
                (int) ($row->sales ?? 0),
                (int) ($row->platform ?? 0),
            );
        }

        return $points;
    }

    /**
     * برترین نمایندگان بازه بر پایه‌ی درآمد پلتفرم از آن‌ها (reseller_price).
     * «درآمد پلتفرم» از نماینده = آنچه ما گرفته‌ایم؛ تفاوتش با پرداخت مشتری، سود خودِ نماینده است (هم‌قاعده با Reports).
     *
     * @return list<TopReseller>
     */
    public function topResellers(DashboardPeriod $period, int $limit = self::TOP_RESELLERS_LIMIT, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $period->range($now ?? now());

        return $this->settledOrdersQuery($from, $to)
            ->whereNotNull('orders.reseller_id')
            ->join('resellers', 'resellers.id', '=', 'orders.reseller_id')
            ->join('users', 'users.id', '=', 'resellers.user_id')
            ->selectRaw('resellers.id as reseller_id, users.full_name as name, resellers.slug as slug')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(orders.customers_price), 0) as sales')
            ->selectRaw('coalesce(sum(orders.reseller_price), 0) as platform')
            ->groupBy('resellers.id', 'users.full_name', 'resellers.slug')
            ->orderByDesc('platform')
            ->orderBy('resellers.id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row) => new TopReseller(
                (int) $row->reseller_id,
                (string) ($row->name ?? ''),
                $row->slug,
                (int) $row->orders,
                (int) $row->sales,
                (int) $row->platform,
            ))
            ->all();
    }

    /**
     * کوئریِ آخرین سفارش‌های پلتفرم (همه‌ی وضعیت‌ها و فروشگاه‌ها، بدون اکانت تست، تازه‌ترین اول).
     * Query برمی‌گردد تا ویجت جدول Filament مستقیم از آن بخواند؛ فقط ستون‌های لازم و بدون N+1.
     */
    public function recentOrdersQuery(int $limit = self::RECENT_ORDERS_LIMIT): Builder
    {
        return $this->realOrdersQuery()
            ->with(['product:id,name', 'user:id,full_name', 'reseller:id,slug'])
            ->latest('orders.id')
            ->limit(max(1, $limit));
    }

    /** @return Collection<int, Order> */
    public function recentOrders(int $limit = self::RECENT_ORDERS_LIMIT): Collection
    {
        return $this->recentOrdersQuery($limit)->get();
    }

    /**
     * کوئریِ سرورهای فعال به ترتیب اولویت: از کار افتاده ← کند ← بقیه، و در هر گروه پرمصرف‌ترین اول.
     * مصرف با ضرب در ۱۰۰۰ و تقسیم محاسبه می‌شود (فقط برای مرتب‌سازی؛ بدون float).
     */
    public function serverHealthQuery(int $limit = self::SERVER_ROWS_LIMIT): Builder
    {
        return ServerPanel::query()
            ->where('status', 'active')
            ->orderByRaw("case health_status when 'down' then 0 when 'degraded' then 1 else 2 end")
            ->orderByRaw('case when capacity is not null and capacity > 0 then active_accounts_count * 1000 / capacity else 0 end desc')
            ->orderBy('id')
            ->limit(max(1, $limit));
    }

    /**
     * «نیازمند توجه» از وضعیت لحظه‌ای؛ خطر → هشدار → اطلاع.
     *
     * @return list<ExecutiveAlert>
     */
    public function alerts(ExecutivePosition $position): array
    {
        $alerts = [];

        if ($position->attentionOrders > 0) {
            $alerts[] = new ExecutiveAlert(
                'attention_orders',
                ExecutiveAlert::TONE_DANGER,
                'سفارش‌های پرداخت‌شده‌ی بدون سرویس',
                $position->attentionOrders.' سفارش پول گرفته شده ولی سرویس آن ساخته نشده است؛ نیازمند رسیدگی یا بازگشت وجه.',
                ExecutiveAlert::TARGET_ATTENTION_ORDERS,
                $position->attentionOrders,
            );
        }

        if ($position->serversDown > 0) {
            $alerts[] = new ExecutiveAlert(
                'servers_down',
                ExecutiveAlert::TONE_DANGER,
                'سرور از دسترس خارج است',
                $position->serversDown.' سرور فعال در وضعیت «از کار افتاده» است.',
                ExecutiveAlert::TARGET_SERVERS,
                $position->serversDown,
            );
        }

        if ($position->pendingPayments > 0) {
            $stale = $position->stalePayments > 0
                ? ' — '.$position->stalePayments.' مورد بیش از '.self::STALE_PAYMENT_HOURS.' ساعت منتظر مانده است.'
                : '.';

            $alerts[] = new ExecutiveAlert(
                'pending_payments',
                $position->stalePayments > 0 ? ExecutiveAlert::TONE_DANGER : ExecutiveAlert::TONE_WARNING,
                'رسیدهای در انتظار بررسی',
                $position->pendingPayments.' رسید به مبلغ {pending} منتظر تأیید یا رد شماست'.$stale,
                ExecutiveAlert::TARGET_PAYMENTS,
                $position->pendingPayments,
                ['pending' => $position->pendingPaymentsAmount],
            );
        }

        if ($position->resellersOutOfCredit > 0) {
            $alerts[] = new ExecutiveAlert(
                'resellers_out_of_credit',
                ExecutiveAlert::TONE_WARNING,
                'نمایندگان بدون اعتبار',
                $position->resellersOutOfCredit.' نماینده‌ی فعال قدرت خرید ندارند و فروش فروشگاهشان رد می‌شود. مجموع بدهی نمایندگان: {debt}.',
                ExecutiveAlert::TARGET_RESELLERS,
                $position->resellersOutOfCredit,
                ['debt' => $position->resellerDebt],
            );
        }

        if ($position->serversDegraded > 0) {
            $alerts[] = new ExecutiveAlert(
                'servers_degraded',
                ExecutiveAlert::TONE_WARNING,
                'سرور با کارایی کاهش‌یافته',
                $position->serversDegraded.' سرور فعال در وضعیت «کند/ناپایدار» است.',
                ExecutiveAlert::TARGET_SERVERS,
                $position->serversDegraded,
            );
        }

        if ($position->serversFull > 0) {
            $alerts[] = new ExecutiveAlert(
                'servers_full',
                ExecutiveAlert::TONE_WARNING,
                'ظرفیت سرور تکمیل است',
                $position->serversFull.' سرور فعال به سقف ظرفیت رسیده و ساخت سرویس جدید روی آن‌ها ممکن نیست.',
                ExecutiveAlert::TARGET_SERVERS,
                $position->serversFull,
            );
        }

        if ($position->openTickets > 0) {
            $high = $position->openHighPriorityTickets > 0
                ? ' (که '.$position->openHighPriorityTickets.' مورد اولویت بالا دارد)'
                : '';

            $alerts[] = new ExecutiveAlert(
                'open_tickets',
                $position->openHighPriorityTickets > 0 ? ExecutiveAlert::TONE_WARNING : ExecutiveAlert::TONE_INFO,
                'تیکت‌های بدون پاسخ',
                $position->openTickets.' تیکت منتظر پاسخ پشتیبانی است'.$high.'.',
                ExecutiveAlert::TARGET_TICKETS,
                $position->openTickets,
            );
        }

        usort($alerts, fn (ExecutiveAlert $a, ExecutiveAlert $b) => $a->severity() <=> $b->severity());

        return $alerts;
    }

    /* ------------------------------------------------------------------
     | داخلی
     * ----------------------------------------------------------------- */

    private function totals(CarbonImmutable $from, CarbonImmutable $to): ExecutiveTotals
    {
        $row = $this->settledOrdersQuery($from, $to)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(case when orders.renews_account_id is null then 0 else 1 end), 0) as renewals')
            // «فروش» = آنچه مشتری پرداخته؛ main_price و customers_price هیچ‌وقت هم‌زمان پر نیستند (بند ۳۱).
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.customers_price, 0)), 0) as sales')
            // «درآمد پلتفرم» = آنچه به ما می‌رسد (فروش مستقیم: main_price؛ فروش نمایندگی: reseller_price).
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.reseller_price, 0)), 0) as platform')
            ->selectRaw('coalesce(sum(case when orders.reseller_id is null then coalesce(orders.main_price, orders.customers_price, 0) else 0 end), 0) as direct_sales')
            ->selectRaw('coalesce(sum(case when orders.reseller_id is null then 0 else 1 end), 0) as reseller_orders')
            ->first();

        $failed = $this->realOrdersQuery()
            ->whereIn('orders.status', self::FAILED_STATUSES)
            ->where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<', $to)
            ->count();

        $newUsers = User::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        $newServices = Account::query()
            ->where('is_test', false)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        $sales = (int) ($row->sales ?? 0);
        $direct = (int) ($row->direct_sales ?? 0);

        return new ExecutiveTotals(
            orders: (int) ($row->orders ?? 0),
            renewals: (int) ($row->renewals ?? 0),
            sales: $sales,
            platformRevenue: (int) ($row->platform ?? 0),
            directSales: $direct,
            resellerSales: $sales - $direct,
            resellerOrders: (int) ($row->reseller_orders ?? 0),
            failedOrders: $failed,
            newUsers: $newUsers,
            newServices: $newServices,
        );
    }

    /** سفارش‌های واقعی: همه‌ی سفارش‌ها به‌جز اکانت تست (که فروش نیستند) */
    private function realOrdersQuery(): Builder
    {
        return Order::query()->where('orders.sales_channel', '!=', 'test_account');
    }

    private function settledOrdersQuery(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->realOrdersQuery()
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<', $to);
    }

    /**
     * رسیدهای در انتظارِ صفِ پلتفرم: خرید/شارژ فروشگاه اصلی، و شارژ اعتبارِ خودِ نماینده (تأییدش با ادمین اصلی است).
     * شارژ کیف‌پول مشتریِ یک نماینده در صف خود نماینده است (هم‌قاعده با PaymentResource نماینده).
     */
    private function platformPendingPaymentsQuery(): Builder
    {
        return Payment::query()
            ->where('status', 'pending')
            ->where(fn (Builder $q) => $q->whereNull('reseller_id')->orWhere('wallet_owner_type', 'reseller'));
    }

    /** جمع موجودی‌های مثبت کیف‌پول‌های Main (پیش‌پرداخت نزد پلتفرم) */
    private function prepaidBalance(): int
    {
        return (int) Wallet::query()
            ->where('scope_key', 'main')
            ->where('balance', '>', 0)
            ->sum('balance');
    }

    /** جمع بدهی نمایندگان = قدرمطلق جمع موجودی‌های منفی Wallet Main صاحبانِ نماینده */
    private function resellerDebt(): int
    {
        $negative = (int) Wallet::query()
            ->where('scope_key', 'main')
            ->where('balance', '<', 0)
            ->whereIn('user_id', Reseller::query()->select('user_id'))
            ->sum('balance');

        return abs($negative);
    }

    /**
     * نمایندگان فعالی که قدرت خرید (موجودی + سقف بدهی) ≤ ۰ دارند. فقط نمایندگانی که Wallet دارند شمرده می‌شوند:
     * نماینده‌ی تازه‌ساخته‌ی بدون Wallet «اعتبار تمام‌شده» نیست و نباید هشدار الکی بدهد.
     */
    private function resellersOutOfCredit(): int
    {
        return Reseller::query()
            ->where('resellers.status', 'active')
            ->join('wallets', function ($join) {
                $join->on('wallets.user_id', '=', 'resellers.user_id')
                    ->where('wallets.scope_key', '=', 'main');
            })
            ->whereRaw('wallets.balance + resellers.debt_limit <= 0')
            ->count();
    }
}
