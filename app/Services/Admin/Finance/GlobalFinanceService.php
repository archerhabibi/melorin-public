<?php

namespace App\Services\Admin\Finance;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Admin\Dashboard\ExecutiveAlert;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * داشبورد مالی سراسری ادمین (B7.3): سود و زیان بازه، جریان نقد، تعهدات لحظه‌ای، سلامت دفتر کیف‌پول‌ها،
 * روش‌های دریافت و مطالبات از نمایندگان — از یک منبع واحد در Core. هم‌خانواده‌ی `ExecutiveDashboardService` (B7.1).
 *
 * قواعد:
 *  - فقط‌خواندنی: هیچ رکورد/Walletی نمی‌سازد و هیچ موجودی‌ای را تغییر نمی‌دهد (Master W5: فقط WalletService).
 *  - ورودی فقط بازه‌ی زمانی است؛ هیچ شناسه‌ای از Request خوانده نمی‌شود.
 *  - هیچ محاسبه‌ی مالی جدیدی نیست: فقط جمع Snapshotهای قیمت سفارش و Ledger (`wallet_transactions`).
 *  - Aggregate در دیتابیس؛ تعداد کوئری به تعداد سفارش/تراکنش/کیف‌پول وابسته نیست. همه‌ی مبالغ int (Minor Unit).
 *  - Core هیچ Route/Filament/Money-format نمی‌شناسد.
 *
 * دو منبع، دو پرسش:
 *  - «سود و زیان» از سفارش‌ها (Accrual): فروش قطعی هم‌تعریف B7.1 (`paid`/`account_created`، بدون اکانت تست).
 *  - «جریان نقد» از Ledger: پولی که واقعاً وارد/خارج کیف‌پول‌ها شده، بر پایه‌ی زمان ثبت تراکنش.
 *
 * مرز پلتفرم = کیف‌پول‌های Main (`store_type=main`؛ شامل اعتبار صاحبان نماینده، W3). کیف‌پول فروشگاه نماینده
 * (`store_type=reseller`) تعهد و هزینه‌ی خود نماینده است (هم‌قاعده با B5.5) و فقط جداگانه گزارش می‌شود.
 * تعریف‌های کامل در ADMIN-GLOBAL-FINANCE-CONTRACT.md.
 */
class GlobalFinanceService
{
    public const DEBTORS_LIMIT = 5;

    /** شرط SQL «کیف‌پول پلتفرم» (Main) */
    private const MAIN = "wallets.store_type = 'main'";

    /** شرط SQL «کیف‌پول فروشگاه نماینده» */
    private const STORE = "wallets.store_type = 'reseller'";

    /** سود و زیان + جریان نقد بازه، با مقایسه‌ی بازه‌ی قبلِ هم‌طول */
    public function summary(DashboardPeriod $period, ?CarbonInterface $now = null): FinanceSummary
    {
        $now ??= now();

        [$from, $to] = $period->range($now);
        [$previousFrom, $previousTo] = $period->previousRange($now);

        return new FinanceSummary(
            $period,
            $from,
            $to,
            $this->totals($from, $to),
            $this->totals($previousFrom, $previousTo),
        );
    }

    /** وضعیت لحظه‌ای (مستقل از بازه): تعهدات، مطالبات، پول در راه، سلامت دفتر */
    public function position(?CarbonInterface $now = null): FinancePosition
    {
        $now = CarbonImmutable::instance($now ?? now());

        // ۱) موجودی‌ها به‌تفکیک صاحب. «صاحب نماینده» = Userی که دست‌کم یک نماینده دارد (Wallet Main او اعتبار تأمین است، W3).
        $owners = DB::table('resellers')->select('user_id')->distinct();

        $balances = DB::table('wallets')
            ->leftJoinSub($owners, 'owners', 'owners.user_id', '=', 'wallets.user_id')
            ->selectRaw('coalesce(sum(case when '.self::MAIN.' and owners.user_id is null and wallets.balance > 0 then wallets.balance else 0 end), 0) as customer_balances')
            ->selectRaw('coalesce(sum(case when '.self::MAIN.' and owners.user_id is null and wallets.balance > 0 then 1 else 0 end), 0) as customer_wallets')
            ->selectRaw('coalesce(sum(case when '.self::MAIN.' and owners.user_id is not null and wallets.balance > 0 then wallets.balance else 0 end), 0) as reseller_credit')
            ->selectRaw('coalesce(sum(case when '.self::MAIN.' and owners.user_id is not null and wallets.balance < 0 then -wallets.balance else 0 end), 0) as reseller_debt')
            ->selectRaw('coalesce(sum(case when '.self::STORE.' and wallets.balance > 0 then wallets.balance else 0 end), 0) as store_balances')
            // کف مجاز مشتری صفر است؛ موجودی منفی فقط برای صاحب نماینده (تا سقف بدهی) معنا دارد.
            ->selectRaw('coalesce(sum(case when wallets.balance < 0 and ('.self::STORE.' or owners.user_id is null) then 1 else 0 end), 0) as negative_wallets')
            ->selectRaw('coalesce(sum(case when wallets.balance < 0 and ('.self::STORE.' or owners.user_id is null) then -wallets.balance else 0 end), 0) as negative_amount')
            ->first();

        // ۲) تراز دفتر: موجودی هر کیف‌پول باید دقیقاً برابر مجموع گردش آن باشد (هم‌تعریف Preflight `ledger_matches_balance`).
        $ledger = DB::table('wallets')
            ->leftJoinSub(
                DB::table('wallet_transactions')->selectRaw('wallet_id, sum(amount) as total')->groupBy('wallet_id'),
                'ledger',
                'ledger.wallet_id',
                '=',
                'wallets.id',
            )
            ->whereRaw('coalesce(ledger.total, 0) <> wallets.balance')
            ->selectRaw('count(*) as n')
            ->selectRaw('coalesce(sum(wallets.balance - coalesce(ledger.total, 0)), 0) as drift')
            ->first();

        // ۳) رسیدهای در انتظار صف پلتفرم (هم‌تعریف B7.1 ED4).
        $receipts = Payment::query()
            ->where('status', 'pending')
            ->where(fn (Builder $q) => $q->whereNull('reseller_id')->orWhere('wallet_owner_type', 'reseller'))
            ->selectRaw('count(*) as n, coalesce(sum(amount), 0) as total')
            ->selectRaw('coalesce(sum(case when created_at <= ? then 1 else 0 end), 0) as stale', [
                $now->subHours(ExecutiveDashboardService::STALE_PAYMENT_HOURS),
            ])
            ->first();

        // ۴) پولی که گرفته شده ولی سرویسش هنوز تحویل نشده (آنچه مشتری پرداخته).
        $paid = 'coalesce(orders.main_price, orders.customers_price, 0)';
        $held = $this->realOrdersQuery()
            ->whereIn('orders.status', [Order::STATUS_PROVISION_FAILED, ...ExecutiveDashboardService::IN_PROGRESS_STATUSES])
            ->selectRaw("coalesce(sum(case when orders.status = ? then 1 else 0 end), 0) as failed_n", [Order::STATUS_PROVISION_FAILED])
            ->selectRaw("coalesce(sum(case when orders.status = ? then {$paid} else 0 end), 0) as failed_amount", [Order::STATUS_PROVISION_FAILED])
            ->selectRaw("coalesce(sum(case when orders.status <> ? then 1 else 0 end), 0) as delivering_n", [Order::STATUS_PROVISION_FAILED])
            ->selectRaw("coalesce(sum(case when orders.status <> ? then {$paid} else 0 end), 0) as delivering_amount", [Order::STATUS_PROVISION_FAILED])
            ->first();

        // ۵) نمایندگان فعالِ بدون قدرت خرید (هم‌تعریف B7.1) و بالای سقف بدهی (قدرت خرید منفی؛ مثلاً پس از کاهش سقف).
        $credit = DB::table('resellers')
            ->join('wallets', function (JoinClause $join): void {
                $join->on('wallets.user_id', '=', 'resellers.user_id')->where('wallets.scope_key', '=', 'main');
            })
            ->where('resellers.status', 'active')
            ->selectRaw('coalesce(sum(case when wallets.balance + resellers.debt_limit <= 0 then 1 else 0 end), 0) as out_of_credit')
            ->selectRaw('coalesce(sum(case when wallets.balance + resellers.debt_limit < 0 then 1 else 0 end), 0) as over_limit')
            ->first();

        return new FinancePosition(
            customerBalances: (int) ($balances->customer_balances ?? 0),
            customerWallets: (int) ($balances->customer_wallets ?? 0),
            resellerCredit: (int) ($balances->reseller_credit ?? 0),
            resellerDebt: (int) ($balances->reseller_debt ?? 0),
            resellerStoreBalances: (int) ($balances->store_balances ?? 0),
            negativeWallets: (int) ($balances->negative_wallets ?? 0),
            negativeAmount: (int) ($balances->negative_amount ?? 0),
            unbalancedWallets: (int) ($ledger->n ?? 0),
            ledgerDrift: (int) ($ledger->drift ?? 0),
            pendingReceipts: (int) ($receipts->n ?? 0),
            pendingReceiptsAmount: (int) ($receipts->total ?? 0),
            staleReceipts: (int) ($receipts->stale ?? 0),
            failedDeliveries: (int) ($held->failed_n ?? 0),
            failedDeliveriesAmount: (int) ($held->failed_amount ?? 0),
            inDelivery: (int) ($held->delivering_n ?? 0),
            inDeliveryAmount: (int) ($held->delivering_amount ?? 0),
            resellersOutOfCredit: (int) ($credit->out_of_credit ?? 0),
            resellersOverLimit: (int) ($credit->over_limit ?? 0),
        );
    }

    /**
     * روند روزانه‌ی درآمد پلتفرم، ورودی نقد و بازگشت‌ها؛ همه‌ی روزهای بازه حاضرند (روز خالی = صفر).
     *
     * @return list<FinanceTrendPoint>
     */
    public function trend(DashboardPeriod $period, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $period->range($now ?? now());

        $revenue = $this->settledOrdersQuery($from, $to)
            ->selectRaw('date(orders.created_at) as day')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.reseller_price, 0)), 0) as platform')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $t = 'wallet_transactions';

        $cash = $this->ledgerInRange($from, $to)
            ->selectRaw("date({$t}.created_at) as day")
            ->selectRaw('coalesce(sum(case when '.$this->cashInSql().' then '.$t.'.amount else 0 end), 0) as cash_in')
            ->selectRaw('coalesce(sum(case when '.$this->paymentRefundSql().' then -'.$t.'.amount else 0 end), 0) as payment_refunds', [$this->paymentMorph()])
            ->selectRaw('coalesce(sum(case when '.$this->orderRefundSql().' then '.$t.'.amount else 0 end), 0) as order_refunds', [$this->orderMorph()])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $points = [];

        foreach (CarbonPeriod::create($from, '1 day', $to->subSecond()) as $day) {
            $key = $day->format('Y-m-d');
            $r = $revenue->get($key);
            $c = $cash->get($key);

            $points[] = new FinanceTrendPoint(
                CarbonImmutable::instance($day)->startOfDay(),
                platformRevenue: (int) ($r->platform ?? 0),
                cashIn: (int) ($c->cash_in ?? 0),
                refunds: (int) ($c->payment_refunds ?? 0) + (int) ($c->order_refunds ?? 0),
            );
        }

        return $points;
    }

    /**
     * دریافت‌ها به‌تفکیک روش پرداخت در بازه: شارژهای تأییدشده (شارژ = تراکنش `charge` با ارجاع به Payment) و
     * بازگشت پرداخت‌ها (`admin_adjust` منفی با ارجاع به Payment؛ بر پایه‌ی زمان بازگشت). بزرگ‌ترین دریافت اول.
     *
     * @return list<PaymentMethodFlow>
     */
    public function paymentMethods(DashboardPeriod $period, ?CarbonInterface $now = null): array
    {
        [$from, $to] = $period->range($now ?? now());

        $t = 'wallet_transactions';
        $payment = $this->paymentMorph();

        return $this->ledgerInRange($from, $to)
            ->join('payments', 'payments.id', '=', "{$t}.reference_id")
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where("{$t}.reference_type", $payment)
            ->whereIn("{$t}.type", ['charge', 'admin_adjust'])
            ->selectRaw('payment_methods.id as method_id, payment_methods.name as name, payment_methods.type as method_type')
            ->selectRaw('coalesce(sum(case when '.$this->cashInSql().' then 1 else 0 end), 0) as charges')
            ->selectRaw('coalesce(sum(case when '.$this->cashInSql().' then '.$t.'.amount else 0 end), 0) as collected')
            ->selectRaw('coalesce(sum(case when '.self::STORE." and {$t}.type = 'charge' and {$t}.amount > 0 then {$t}.amount else 0 end), 0) as store_collected")
            ->selectRaw('coalesce(sum(case when '.$this->paymentRefundSql().' then -'.$t.'.amount else 0 end), 0) as refunded', [$payment])
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type')
            ->orderByDesc('collected')
            ->orderByDesc('store_collected')
            ->orderBy('payment_methods.id')
            ->get()
            ->map(fn ($row) => new PaymentMethodFlow(
                methodId: $row->method_id !== null ? (int) $row->method_id : null,
                name: (string) ($row->name ?? ''),
                type: $row->method_type,
                charges: (int) $row->charges,
                collected: (int) $row->collected,
                storeCollected: (int) $row->store_collected,
                refunded: (int) $row->refunded,
            ))
            ->all();
    }

    /**
     * بدهکارترین نمایندگان (موجودی منفی Wallet Main صاحب، W3)؛ بیشترین بدهی اول. فعال و غیرفعال هر دو، چون بدهی
     * نماینده‌ی غیرفعال هم مطالبه‌ی پلتفرم است.
     *
     * @return list<ResellerDebtor>
     */
    public function debtors(int $limit = self::DEBTORS_LIMIT): array
    {
        return DB::table('resellers')
            ->join('wallets', function (JoinClause $join): void {
                $join->on('wallets.user_id', '=', 'resellers.user_id')->where('wallets.scope_key', '=', 'main');
            })
            ->leftJoin('users', 'users.id', '=', 'resellers.user_id')
            ->where('wallets.balance', '<', 0)
            ->select([
                'resellers.id as reseller_id',
                'resellers.slug as slug',
                'resellers.status as status',
                'resellers.debt_limit as debt_limit',
                'wallets.balance as balance',
                'users.full_name as name',
            ])
            ->orderBy('wallets.balance')
            ->orderBy('resellers.id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($row) => new ResellerDebtor(
                resellerId: (int) $row->reseller_id,
                name: (string) ($row->name ?? ''),
                slug: $row->slug,
                active: $row->status === 'active',
                balance: (int) $row->balance,
                debtLimit: (int) ($row->debt_limit ?? 0),
            ))
            ->all();
    }

    /**
     * «سلامت مالی» از وضعیت لحظه‌ای؛ خطر → هشدار. همان شیء `ExecutiveAlert` B7.1 (قالب‌بندی مبلغ فقط در Channel).
     *
     * @return list<ExecutiveAlert>
     */
    public function alerts(FinancePosition $position): array
    {
        $alerts = [];

        if ($position->unbalancedWallets > 0) {
            $alerts[] = new ExecutiveAlert(
                'ledger_drift',
                ExecutiveAlert::TONE_DANGER,
                'دفتر کیف‌پول‌ها تراز نیست',
                'موجودی '.$position->unbalancedWallets.' کیف‌پول با مجموع گردش آن برابر نیست (اختلاف خالص {drift}). '
                    .'پیش از هر تسویه یا اصلاح موجودی، با دستور melorin:preflight بررسی شود.',
                ExecutiveAlert::TARGET_NONE,
                $position->unbalancedWallets,
                ['drift' => $position->ledgerDrift],
            );
        }

        if ($position->negativeWallets > 0) {
            $alerts[] = new ExecutiveAlert(
                'negative_customer_wallets',
                ExecutiveAlert::TONE_DANGER,
                'کیف‌پول مشتری با موجودی منفی',
                $position->negativeWallets.' کیف‌پول مشتری (کف مجاز صفر) موجودی منفی دارد؛ جمعاً {amount}.',
                ExecutiveAlert::TARGET_NONE,
                $position->negativeWallets,
                ['amount' => $position->negativeAmount],
            );
        }

        if ($position->resellersOverLimit > 0) {
            $alerts[] = new ExecutiveAlert(
                'resellers_over_limit',
                ExecutiveAlert::TONE_DANGER,
                'بدهی نماینده بیش از سقف مجاز',
                $position->resellersOverLimit.' نماینده‌ی فعال بیش از سقف بدهی‌اش بدهکار است (مثلاً پس از کاهش سقف). مجموع مطالبات از نمایندگان: {debt}.',
                ExecutiveAlert::TARGET_RESELLERS,
                $position->resellersOverLimit,
                ['debt' => $position->resellerDebt],
            );
        }

        if ($position->failedDeliveries > 0) {
            $alerts[] = new ExecutiveAlert(
                'paid_without_service',
                ExecutiveAlert::TONE_WARNING,
                'پول گرفته شده، سرویس ساخته نشده',
                $position->failedDeliveries.' سفارش به مبلغ {amount} پرداخت شده ولی ساخت سرویس آن ناموفق مانده است؛ باید ساخته یا بازگردانده شود.',
                ExecutiveAlert::TARGET_ATTENTION_ORDERS,
                $position->failedDeliveries,
                ['amount' => $position->failedDeliveriesAmount],
            );
        }

        if ($position->staleReceipts > 0) {
            $alerts[] = new ExecutiveAlert(
                'stale_receipts',
                ExecutiveAlert::TONE_WARNING,
                'رسیدهای کهنه در صف پلتفرم',
                $position->staleReceipts.' رسید بیش از '.ExecutiveDashboardService::STALE_PAYMENT_HOURS.' ساعت منتظر بررسی است؛ کل صف: {pending}.',
                ExecutiveAlert::TARGET_PAYMENTS,
                $position->staleReceipts,
                ['pending' => $position->pendingReceiptsAmount],
            );
        }

        usort($alerts, fn (ExecutiveAlert $a, ExecutiveAlert $b) => $a->severity() <=> $b->severity());

        return $alerts;
    }

    /* ------------------------------------------------------------------
     | داخلی
     * ----------------------------------------------------------------- */

    private function totals(CarbonImmutable $from, CarbonImmutable $to): FinanceTotals
    {
        $orders = $this->settledOrdersQuery($from, $to)
            ->selectRaw('count(*) as orders')
            // هم‌تعریف B7.1: فروش = آنچه مشتری پرداخته؛ درآمد پلتفرم = آنچه به ما می‌رسد.
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.customers_price, 0)), 0) as sales')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.reseller_price, 0)), 0) as platform')
            ->selectRaw('coalesce(sum(case when orders.reseller_id is null then coalesce(orders.main_price, orders.reseller_price, 0) else 0 end), 0) as direct')
            ->first();

        $t = 'wallet_transactions';
        $main = self::MAIN;
        $order = $this->orderMorph();
        $payment = $this->paymentMorph();

        $ledger = $this->ledgerInRange($from, $to)
            ->selectRaw('coalesce(sum(case when '.$this->cashInSql()." then {$t}.amount else 0 end), 0) as cash_in")
            ->selectRaw('coalesce(sum(case when '.$this->cashInSql().' then 1 else 0 end), 0) as cash_in_count')
            ->selectRaw('coalesce(sum(case when '.self::STORE." and {$t}.type = 'charge' and {$t}.amount > 0 then {$t}.amount else 0 end), 0) as store_cash_in")
            ->selectRaw('coalesce(sum(case when '.$this->paymentRefundSql()." then -{$t}.amount else 0 end), 0) as payment_refunds", [$payment])
            ->selectRaw("coalesce(sum(case when {$main} and {$t}.type = 'admin_adjust' and ({$t}.reference_type is null or {$t}.reference_type <> ?) then {$t}.amount else 0 end), 0) as manual_adjustments", [$payment])
            ->selectRaw("coalesce(sum(case when {$main} and {$t}.type = 'commission' and {$t}.amount > 0 then {$t}.amount else 0 end), 0) as commissions")
            ->selectRaw("coalesce(sum(case when {$main} and {$t}.type = 'referral_bonus' and {$t}.amount > 0 then {$t}.amount else 0 end), 0) as bonuses")
            ->selectRaw('coalesce(sum(case when '.$this->orderRefundSql()." then {$t}.amount else 0 end), 0) as order_refunds", [$order])
            ->selectRaw('count(distinct case when '.$this->orderRefundSql()." then {$t}.reference_id end) as refunded_orders", [$order])
            ->first();

        return new FinanceTotals(
            orders: (int) ($orders->orders ?? 0),
            sales: (int) ($orders->sales ?? 0),
            platformRevenue: (int) ($orders->platform ?? 0),
            directRevenue: (int) ($orders->direct ?? 0),
            commissions: (int) ($ledger->commissions ?? 0),
            referralBonuses: (int) ($ledger->bonuses ?? 0),
            orderRefunds: (int) ($ledger->order_refunds ?? 0),
            refundedOrders: (int) ($ledger->refunded_orders ?? 0),
            cashIn: (int) ($ledger->cash_in ?? 0),
            cashInCount: (int) ($ledger->cash_in_count ?? 0),
            paymentRefunds: (int) ($ledger->payment_refunds ?? 0),
            manualAdjustments: (int) ($ledger->manual_adjustments ?? 0),
            resellerStoreCashIn: (int) ($ledger->store_cash_in ?? 0),
        );
    }

    /** سفارش‌های واقعی: همه به‌جز اکانت تست (هم‌تعریف B7.1) */
    private function realOrdersQuery(): Builder
    {
        return Order::query()->where('orders.sales_channel', '!=', 'test_account');
    }

    private function settledOrdersQuery(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->realOrdersQuery()
            ->whereIn('orders.status', ExecutiveDashboardService::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<', $to);
    }

    /** تراکنش‌های Ledger در بازه‌ی نیمه‌باز، همراه با کیف‌پول (برای تشخیص Main/فروشگاه نماینده) */
    private function ledgerInRange(CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        return DB::table('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallet_transactions.created_at', '>=', $from)
            ->where('wallet_transactions.created_at', '<', $to);
    }

    /** ورودی نقد پلتفرم: شارژ تأییدشده‌ی کیف‌پول Main (مشتریان Main و اعتبار نمایندگان) */
    private function cashInSql(): string
    {
        return self::MAIN." and wallet_transactions.type = 'charge' and wallet_transactions.amount > 0";
    }

    /** بازگشت پرداخت: کسر `admin_adjust` با ارجاع به Payment از کیف‌پول Main (PaymentService::refund). یک binding. */
    private function paymentRefundSql(): string
    {
        return self::MAIN." and wallet_transactions.type = 'admin_adjust' and wallet_transactions.amount < 0 and wallet_transactions.reference_type = ?";
    }

    /** بازگشت وجه سفارش به کیف‌پول Main (سهم پلتفرم؛ RefundService). یک binding. */
    private function orderRefundSql(): string
    {
        return self::MAIN." and wallet_transactions.type = 'refund' and wallet_transactions.amount > 0 and wallet_transactions.reference_type = ?";
    }

    private function orderMorph(): string
    {
        return (new Order)->getMorphClass();
    }

    private function paymentMorph(): string
    {
        return (new Payment)->getMorphClass();
    }
}
