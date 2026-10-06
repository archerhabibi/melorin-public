<?php

namespace App\Services\Resellers\Finance;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Resellers\Concerns\AggregatesFilteredQuery;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * مرکز مالی نماینده (B5.5): اعتبار تأمین، گردش اعتبار و صورت‌حساب بازه — از یک منبع واحد در Core.
 *
 * قواعد (هم‌راستا با B5.1–B5.4):
 *  - فقط‌خواندنی: هیچ رکورد/Walletی نمی‌سازد و موجودی را تغییر نمی‌دهد (Master W5: فقط WalletService). برداشت/تسویه‌ی
 *    پولی در Contract تعریف نشده و این‌جا وجود ندارد.
 *  - «اعتبار نماینده» = Wallet صاحب او در Main (W3)؛ Wallet جدا ندارد. چون همان Wallet برای خریدهای شخصیِ صاحب در Main هم
 *    هست، «گردش اعتبار» عمداً فقط حرکت‌های مربوط به اعتبار را نشان می‌دهد: هزینه‌ی تأمین/بازگشتِ سفارش‌های همین
 *    فروشگاه، شارژ و اصلاح مدیر. خریدهای شخصی صاحب (Main) هرگز نمی‌آیند — اپراتورِ دیگرِ پنل نباید آن‌ها را ببیند.
 *  - جداسازی: تراکنش تأمین فقط وقتی «مال این نماینده» است که به سفارشی با `orders.reseller_id` همین نماینده اشاره کند.
 *  - هیچ محاسبه‌ی مالی جدیدی نیست؛ فقط جمع/نمایش Snapshotهای ثبت‌شده (Ledger و قیمت‌های سفارش).
 *  - بدون N+1؛ جمع‌ها یک کوئری Aggregate روی همان Query فیلترشده‌ی جدول‌اند. همه int.
 */
class ResellerFinanceCenter
{
    use AggregatesFilteredQuery;

    public const KIND_SUPPLY = 'supply';

    public const KIND_CHARGE = 'charge';

    public const KIND_ADJUST = 'adjust';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_SUPPLY => 'هزینه‌ی تأمین سفارش',
            self::KIND_CHARGE => 'شارژ اعتبار',
            self::KIND_ADJUST => 'اصلاح توسط مدیر',
        ];
    }

    /** @return array<string, string> */
    public static function directionLabels(): array
    {
        return [self::DIRECTION_IN => 'افزایش اعتبار', self::DIRECTION_OUT => 'کاهش اعتبار'];
    }

    /** نوع نمایشیِ یک ردیف گردش (هم‌تعریف با ledgerQuery) */
    public static function kindOf(WalletTransaction $tx): string
    {
        return match ($tx->type) {
            'charge' => self::KIND_CHARGE,
            'admin_adjust' => self::KIND_ADJUST,
            default => self::KIND_SUPPLY,
        };
    }

    /** وضعیت لحظه‌ی اعتبار؛ بدون ساختن Wallet (نبودنش ⇒ موجودی ۰). */
    public function credit(Reseller $reseller): CreditPosition
    {
        $wallet = $this->ownerWalletQuery($reseller)->first(['id', 'balance']);

        $pending = Payment::query()
            ->where('reseller_id', $reseller->id)
            ->where('wallet_owner_type', 'reseller')
            ->where('status', 'pending')
            ->selectRaw('count(*) as n, coalesce(sum(amount), 0) as total')
            ->first();

        return new CreditPosition(
            balance: (int) ($wallet?->balance ?? 0),
            debtLimit: $reseller->debtLimit(),
            hasWallet: $wallet !== null,
            pendingChargeCount: (int) ($pending->n ?? 0),
            pendingChargeAmount: (int) ($pending->total ?? 0),
        );
    }

    /**
     * گردش اعتبار این نماینده (جدیدترین اول به‌عهده‌ی جدول): فقط حرکت‌های مربوط به اعتبار تأمین.
     * Wallet نداشتن ⇒ مجموعه‌ی خالی (بدون ساخت).
     */
    public function ledgerQuery(Reseller $reseller): Builder
    {
        $orderOfThisStore = fn () => DB::table('orders')
            ->whereColumn('orders.id', 'wallet_transactions.reference_id')
            ->where('orders.reseller_id', $reseller->id);

        return WalletTransaction::query()
            ->select('wallet_transactions.*')
            ->whereIn('wallet_transactions.wallet_id', $this->ownerWalletQuery($reseller)->select('id'))
            ->where(function (Builder $w) use ($orderOfThisStore): void {
                $w->whereIn('wallet_transactions.type', ['charge', 'admin_adjust'])
                    ->orWhere(function (Builder $supply) use ($orderOfThisStore): void {
                        $supply->whereIn('wallet_transactions.type', ['purchase', 'refund'])
                            ->where('wallet_transactions.reference_type', (new Order)->getMorphClass())
                            ->whereExists($orderOfThisStore());
                    });
            });
    }

    /** فیلتر نوع؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyKind(Builder $query, mixed $kind): Builder
    {
        return match ($kind) {
            self::KIND_SUPPLY => $query->whereIn('wallet_transactions.type', ['purchase', 'refund']),
            self::KIND_CHARGE => $query->where('wallet_transactions.type', 'charge'),
            self::KIND_ADJUST => $query->where('wallet_transactions.type', 'admin_adjust'),
            default => $query,
        };
    }

    /** فیلتر جهت؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyDirection(Builder $query, mixed $direction): Builder
    {
        return match ($direction) {
            self::DIRECTION_IN => $query->where('wallet_transactions.amount', '>', 0),
            self::DIRECTION_OUT => $query->where('wallet_transactions.amount', '<', 0),
            default => $query,
        };
    }

    /** بازه‌ی زمانی (نیمه‌باز، هم‌تعریف B5.1)؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyPeriod(Builder $query, mixed $period, ?CarbonInterface $now = null): Builder
    {
        $period = is_string($period) ? DashboardPeriod::tryFrom($period) : null;

        if (! $period) {
            return $query;
        }

        [$from, $to] = $period->range($now ?? CarbonImmutable::now());

        return $query->where('wallet_transactions.created_at', '>=', $from)->where('wallet_transactions.created_at', '<', $to);
    }

    /** جمع‌های همان Query فیلترشده‌ی جدول؛ یک کوئری. */
    public function ledgerTotals(Builder $filtered): LedgerTotals
    {
        $t = 'wallet_transactions';

        $row = $this->aggregateBase($filtered)->selectRaw(implode(', ', [
            'count(*) as n',
            $this->sumWhen("{$t}.amount > 0", "{$t}.amount", 'credited'),
            $this->sumWhen("{$t}.amount < 0", "-{$t}.amount", 'debited'),
            $this->sumWhen("{$t}.type = 'purchase'", "-{$t}.amount", 'supply_spent'),
            $this->sumWhen("{$t}.type = 'refund'", "{$t}.amount", 'supply_refunded'),
            $this->sumWhen("{$t}.type = 'charge'", "{$t}.amount", 'charged'),
            $this->sumWhen("{$t}.type = 'admin_adjust'", "{$t}.amount", 'adjusted'),
        ]))->first();

        return new LedgerTotals(
            count: (int) $row->n,
            credited: (int) $row->credited,
            debited: (int) $row->debited,
            supplySpent: (int) $row->supply_spent,
            supplyRefunded: (int) $row->supply_refunded,
            charged: (int) $row->charged,
            adjustedNet: (int) $row->adjusted,
        );
    }

    /**
     * صورت‌حساب بازه. چهار کوئری ثابت: سفارش‌ها، کیف‌پول‌های فروشگاه (شارژ/هدیه + مانده)، شارژ اعتبار صاحب.
     * «فروش قطعی» همان تعریف داشبورد (paid و account_created) است تا یک عدد در دو صفحه دو مقدار نشان ندهد.
     */
    public function statement(Reseller $reseller, DashboardPeriod $period, ?CarbonInterface $now = null): FinanceStatement
    {
        [$from, $to] = $period->range($now ?? CarbonImmutable::now());

        $orders = Order::query()
            ->ofReseller($reseller->id)
            ->whereIn('status', ResellerDashboardService::REVENUE_STATUSES)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('count(*) as n, coalesce(sum(customers_price), 0) as revenue, coalesce(sum(reseller_price), 0) as cost')
            ->first();

        $store = DB::table('wallet_transactions')
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->where('wallets.reseller_id', $reseller->id)
            ->where('wallets.scope_key', Wallet::scopeKeyFor('reseller', $reseller->id))
            ->where('wallet_transactions.created_at', '>=', $from)
            ->where('wallet_transactions.created_at', '<', $to)
            ->selectRaw("coalesce(sum(case when wallet_transactions.type = 'commission' and wallet_transactions.amount > 0 then wallet_transactions.amount else 0 end), 0) as commissions")
            ->selectRaw("coalesce(sum(case when wallet_transactions.type = 'referral_bonus' and wallet_transactions.amount > 0 then wallet_transactions.amount else 0 end), 0) as bonuses")
            ->selectRaw("coalesce(sum(case when wallet_transactions.type = 'charge' and wallet_transactions.amount > 0 then wallet_transactions.amount else 0 end), 0) as charges")
            ->first();

        $balances = (int) Wallet::query()
            ->where('reseller_id', $reseller->id)
            ->where('scope_key', Wallet::scopeKeyFor('reseller', $reseller->id))
            ->sum('balance');

        $ownerCharges = (int) $this->applyPeriod(
            $this->applyKind($this->ledgerQuery($reseller), self::KIND_CHARGE),
            $period->value,
            $now,
        )->where('wallet_transactions.amount', '>', 0)->sum('wallet_transactions.amount');

        return new FinanceStatement(
            period: $period,
            from: $from,
            to: $to,
            orders: (int) ($orders->n ?? 0),
            revenue: (int) ($orders->revenue ?? 0),
            supplyCost: (int) ($orders->cost ?? 0),
            commissionsGiven: (int) ($store->commissions ?? 0),
            bonusesGiven: (int) ($store->bonuses ?? 0),
            customerCharges: (int) ($store->charges ?? 0),
            ownerCharges: $ownerCharges,
            customerWalletBalances: $balances,
        );
    }

    /** Wallet صاحب نماینده در Main (بدون ساخت). */
    private function ownerWalletQuery(Reseller $reseller): Builder
    {
        return Wallet::query()
            ->where('user_id', $reseller->user_id)
            ->where('scope_key', 'main');
    }
}
