<?php

namespace App\Services\Resellers\Dashboard;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * داشبورد نماینده (B5.1): درآمد، مشتریان، سفارش‌ها، کیف‌پول — از یک منبع واحد در Core.
 *
 * قواعد (هم‌راستا با CustomerDashboardService / B3.1):
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد، حتی Wallet خالی. (WalletService::balance در صورت نبودنِ Wallet آن را
 *    می‌سازد؛ باز کردن یک صفحه نباید در DB بنویسد، پس موجودی مستقیم خوانده می‌شود.)
 *  - Scope-شده به همین نماینده در همه‌ی کوئری‌ها (Order::ofReseller، reseller_id پرداخت و عضویت). هیچ
 *    شناسه‌ای از Request خوانده نمی‌شود؛ ورودی فقط خودِ Reseller است.
 *  - Aggregate در دیتابیس (SUM/COUNT/GROUP BY)؛ تعداد کوئری به تعداد سفارش‌ها وابسته نیست.
 *  - همه‌ی مبالغ int (Minor Unit) و بدون float.
 *  - منطق نمایش (URL، رنگ، Livewire) این‌جا نیست؛ Channel فقط نمایش می‌دهد.
 *
 * تعریف «فروش قطعی‌شده» عمداً همان تعریف پنل ادمین (Reports) و ویجت قبلی است — paid و account_created — تا
 * یک عدد درآمد در دو جا دو مقدار نشان ندهد. سفارش‌های «ساخت ناموفق» جدا در صف رسیدگی شمرده می‌شوند.
 */
class ResellerDashboardService
{
    /** وضعیت‌هایی که «فروش قطعی» حساب می‌شوند (هم‌تعریف با Reports ادمین) */
    public const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED];

    /** «در حال تحویل»: پول گرفته شده و سرویس در راه است */
    public const IN_PROGRESS_STATUSES = [Order::STATUS_PAID, Order::STATUS_PROVISIONING];

    /** «نزدیک انقضا»: انقضا در این تعداد روز آینده */
    public const EXPIRING_DAYS = 7;

    public const RECENT_ORDERS_LIMIT = 8;

    /** KPIهای بازه + مقایسه با بازه‌ی قبلِ هم‌طول */
    public function summary(Reseller $reseller, DashboardPeriod $period, ?CarbonInterface $now = null): ResellerSummary
    {
        $now ??= now();

        [$from, $to] = $period->range($now);
        [$previousFrom, $previousTo] = $period->previousRange($now);

        return new ResellerSummary(
            $period,
            $from,
            $to,
            $this->totals($reseller, $from, $to),
            $this->totals($reseller, $previousFrom, $previousTo),
        );
    }

    /** وضعیت لحظه‌ای (مستقل از بازه): اعتبار، صف رسیدگی، سرویس‌ها */
    public function position(Reseller $reseller, ?CarbonInterface $now = null): ResellerPosition
    {
        $now ??= now();

        $pending = $this->pendingPaymentsQuery($reseller)
            ->selectRaw('count(*) as n, coalesce(sum(amount), 0) as total')
            ->first();

        $orderCounts = Order::query()
            ->ofReseller($reseller->id)
            ->whereIn('status', [Order::STATUS_PROVISION_FAILED, ...self::IN_PROGRESS_STATUSES])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $inProgress = (int) collect(self::IN_PROGRESS_STATUSES)->sum(fn (string $s) => (int) ($orderCounts[$s] ?? 0));

        // سرویس‌های آزمایشی (is_test) فروش نیستند؛ در شمارش سرویس‌ها نمی‌آیند.
        $services = fn (): Builder => $this->soldServicesQuery($reseller)->activeNow();

        return new ResellerPosition(
            balance: $this->balance($reseller),
            debtLimit: $reseller->debtLimit(),
            pendingPayments: (int) ($pending->n ?? 0),
            pendingPaymentsAmount: (int) ($pending->total ?? 0),
            attentionOrders: (int) ($orderCounts[Order::STATUS_PROVISION_FAILED] ?? 0),
            inProgressOrders: $inProgress,
            totalCustomers: $this->customersQuery($reseller)->count(),
            activeServices: $services()->count(),
            expiringServices: $services()
                ->where('expires_at', '<=', CarbonImmutable::instance($now)->addDays(self::EXPIRING_DAYS))
                ->count(),
        );
    }

    /**
     * روند روزانه‌ی فروش در بازه؛ همه‌ی روزها هستند (روز بدون فروش = صفر).
     *
     * @return list<TrendPoint>
     */
    public function trend(Reseller $reseller, DashboardPeriod $period, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $period->range($now ?? now());

        $byDay = $this->settledOrdersQuery($reseller, $from, $to)
            ->selectRaw('date(created_at) as day, count(*) as orders, coalesce(sum(customers_price), 0) as revenue, coalesce(sum(coalesce(customers_price, 0) - coalesce(reseller_price, 0)), 0) as profit')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $points = [];

        foreach (CarbonPeriod::create($from, '1 day', $to->subSecond()) as $day) {
            $key = $day->format('Y-m-d');
            $row = $byDay->get($key);

            $points[] = new TrendPoint(
                CarbonImmutable::instance($day)->startOfDay(),
                (int) ($row->orders ?? 0),
                (int) ($row->revenue ?? 0),
                (int) ($row->profit ?? 0),
            );
        }

        return $points;
    }

    /**
     * کوئریِ آخرین سفارش‌های همین نماینده (همه‌ی وضعیت‌ها، تازه‌ترین اول). فقط ستون‌های لازم و بدون N+1.
     * Query برمی‌گردد تا ویجت جدول Filament مستقیم از آن بخواند و Scope هم‌جا یکی بماند.
     */
    public function recentOrdersQuery(Reseller $reseller, int $limit = self::RECENT_ORDERS_LIMIT): Builder
    {
        return Order::query()
            ->ofReseller($reseller->id)
            ->with(['product:id,name', 'user:id,full_name'])
            ->latest('id')
            ->limit(max(1, $limit));
    }

    /** @return Collection<int, Order> */
    public function recentOrders(Reseller $reseller, int $limit = self::RECENT_ORDERS_LIMIT): Collection
    {
        return $this->recentOrdersQuery($reseller, $limit)->get();
    }

    /**
     * «نیازمند توجه» از وضعیت لحظه‌ای؛ خطر → هشدار → اطلاع.
     *
     * @return list<ResellerAlert>
     */
    public function alerts(ResellerPosition $position): array
    {
        $alerts = [];

        if ($position->isOutOfCredit()) {
            $alerts[] = new ResellerAlert(
                'out_of_credit',
                ResellerAlert::TONE_DANGER,
                'اعتبار شما تمام شده است',
                'با اعتبار فعلی، خریدِ مشتریان از فروشگاه شما رد می‌شود. اعتبارتان را شارژ کنید.',
                ResellerAlert::TARGET_CREDIT,
            );
        } elseif ($position->isInDebt()) {
            $alerts[] = new ResellerAlert(
                'in_debt',
                ResellerAlert::TONE_WARNING,
                'اعتبار شما منفی است',
                'بدهی فعلی: {debt} — هنوز {power} تا سقف بدهی مجاز باقی مانده است.',
                ResellerAlert::TARGET_CREDIT,
                0,
                ['debt' => abs($position->balance), 'power' => $position->purchasingPower()],
            );
        }

        if ($position->attentionOrders > 0) {
            $alerts[] = new ResellerAlert(
                'attention_orders',
                ResellerAlert::TONE_DANGER,
                'سفارش‌های نیازمند رسیدگی',
                $position->attentionOrders.' سفارش پرداخت شده ولی سرویس آن ساخته نشده است. با پشتیبانی هماهنگ کنید.',
                ResellerAlert::TARGET_ATTENTION_ORDERS,
                $position->attentionOrders,
            );
        }

        if ($position->pendingPayments > 0) {
            $alerts[] = new ResellerAlert(
                'pending_payments',
                ResellerAlert::TONE_WARNING,
                'شارژهای در انتظار تأیید شما',
                $position->pendingPayments.' رسید به مبلغ {pending} منتظر تأیید یا رد است.',
                ResellerAlert::TARGET_PAYMENTS,
                $position->pendingPayments,
                ['pending' => $position->pendingPaymentsAmount],
            );
        }

        if ($position->expiringServices > 0) {
            $alerts[] = new ResellerAlert(
                'expiring_services',
                ResellerAlert::TONE_INFO,
                'سرویس‌های رو‌به‌انقضا',
                $position->expiringServices.' سرویس مشتریان شما تا '.self::EXPIRING_DAYS.' روز آینده منقضی می‌شود.',
                ResellerAlert::TARGET_EXPIRING_SERVICES,
                $position->expiringServices,
            );
        }

        usort($alerts, fn (ResellerAlert $a, ResellerAlert $b) => $a->severity() <=> $b->severity());

        return $alerts;
    }

    /* ------------------------------------------------------------------
     | داخلی
     * ----------------------------------------------------------------- */

    private function totals(Reseller $reseller, CarbonImmutable $from, CarbonImmutable $to): PeriodTotals
    {
        $row = $this->settledOrdersQuery($reseller, $from, $to)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(case when renews_account_id is null then 0 else 1 end), 0) as renewals')
            ->selectRaw('coalesce(sum(customers_price), 0) as revenue')
            ->selectRaw('coalesce(sum(reseller_price), 0) as cost')
            // همان فرمول Order::resellerProfit() (null ⇒ ۰)، ولی در دیتابیس.
            ->selectRaw('coalesce(sum(coalesce(customers_price, 0) - coalesce(reseller_price, 0)), 0) as profit')
            ->first();

        $newCustomers = $this->customersQuery($reseller)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        return new PeriodTotals(
            orders: (int) ($row->orders ?? 0),
            renewals: (int) ($row->renewals ?? 0),
            revenue: (int) ($row->revenue ?? 0),
            cost: (int) ($row->cost ?? 0),
            profit: (int) ($row->profit ?? 0),
            newCustomers: $newCustomers,
        );
    }

    private function settledOrdersQuery(Reseller $reseller, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Order::query()
            ->ofReseller($reseller->id)
            ->whereIn('status', self::REVENUE_STATUSES)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);
    }

    /** مشتریان فعالِ فروشگاه همین نماینده — هم‌تعریف با Reseller::customers() */
    private function customersQuery(Reseller $reseller): Builder
    {
        return CustomerAccount::query()
            ->where('store_type', 'reseller')
            ->where('reseller_id', $reseller->id)
            ->where('status', 'active');
    }

    /** سرویس‌های فروخته‌شده (غیرآزمایشی) به مشتریانِ فروشگاه این نماینده */
    private function soldServicesQuery(Reseller $reseller): Builder
    {
        return Account::query()
            ->where('is_test', false)
            ->whereIn('customer_account_id', CustomerAccount::query()
                ->where('store_type', 'reseller')
                ->where('reseller_id', $reseller->id)
                ->select('id'));
    }

    /** شارژهای مشتریان این نماینده که تأییدشان با خود اوست (هم‌تعریف با PaymentResource نماینده) */
    private function pendingPaymentsQuery(Reseller $reseller): Builder
    {
        return Payment::query()
            ->where('reseller_id', $reseller->id)
            ->where('wallet_owner_type', 'user')
            ->where('status', 'pending');
    }

    /** موجودی اعتبار نماینده (Wallet صاحب او در Main). بدون ساختن Wallet. */
    private function balance(Reseller $reseller): int
    {
        return (int) (Wallet::query()
            ->where('user_id', $reseller->user_id)
            ->where('scope_key', 'main')
            ->value('balance') ?? 0);
    }
}
