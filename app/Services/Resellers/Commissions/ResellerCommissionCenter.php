<?php

namespace App\Services\Resellers\Commissions;

use App\Models\AffiliateSetting;
use App\Models\Commission;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\Concerns\AggregatesFilteredQuery;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * مرکز کمیسیون نماینده (B5.4): کمیسیون و پاداش‌های معرفی که در فروشگاه «همین» نماینده پرداخت شده — از یک منبع واحد در Core.
 *
 * قواعد (هم‌راستا با B5.1–B5.3):
 *  - فقط‌خواندنی: هیچ رکورد/Walletی نمی‌سازد و نمی‌نویسد. به‌ویژه تنظیمات سراسری با `first()` خوانده می‌شود،
 *    نه `AffiliateSetting::current()` که در نبودِ رکورد آن را می‌ساخت (باز کردن یک صفحه نباید در DB بنویسد).
 *  - هیچ محاسبه‌ی کمیسیونی این‌جا نیست (Website/پنل نباید Commission محاسبه کند؛ Master §11). مبلغ، نرخ و مبنا همه
 *    Snapshotِ ثبت‌شده‌ی `CommissionService` هستند و فقط خوانده/جمع می‌شوند.
 *  - جداسازی: کمیسیون «مال این نماینده» است اگر سفارشش در فروشگاه او ثبت شده باشد (`orders.reseller_id`)؛ همان قاعده‌ای
 *    که CommissionService پرداخت را در فروشگاه خرید انجام می‌دهد. معرفی که جای دیگر هم حساب دارد پرداخت‌های آن‌جا را این‌جا نمی‌بیند.
 *  - «کمیسیون» و «پاداش معرفی» دو مفهوم مستقل‌اند (Master §11) و همیشه جدا شمرده می‌شوند.
 *  - بدون N+1: ستون‌های محاسبه‌شده Subquery و روابط Eager-load هستند؛ جمع‌ها یک کوئری Aggregate روی همان Query فیلترشده‌ی جدول‌اند
 *    (عددهای بالای صفحه همان چیزی‌ست که جدول نشان می‌دهد).
 *  - همه‌ی مبالغ int (Minor Unit)؛ قالب‌بندی کار Channel است (Master M2).
 */
class ResellerCommissionCenter
{
    use AggregatesFilteredQuery;

    public const KIND_COMMISSION = 'commission';

    public const KIND_BONUS = 'bonus';

    public const ATTENTION_REFUNDED = 'refunded_order';

    public const ATTENTION_EXCEEDS_PROFIT = 'exceeds_profit';

    public const TOP_REFERRERS_LIMIT = 5;

    /** سود سفارش = customers_price − reseller_price (هم‌تعریف با Order::resellerProfit؛ فقط عدد گزارشی) */
    private const ORDER_PROFIT_SQL = '(select coalesce(o.customers_price, 0) - coalesce(o.reseller_price, 0) from orders o where o.id = commissions.order_id)';

    private const ORDER_REFUNDED_SQL = "(select o.status from orders o where o.id = commissions.order_id) = 'refunded'";

    /** @return array<string, string> */
    public static function kindLabels(): array
    {
        return [
            self::KIND_COMMISSION => Commission::typeLabels()[Commission::TYPE_ONGOING],
            self::KIND_BONUS => Commission::typeLabels()[Commission::TYPE_FIRST_PURCHASE],
        ];
    }

    /** @return array<string, string> */
    public static function attentionLabels(): array
    {
        return [
            self::ATTENTION_REFUNDED => 'روی سفارش بازگشت‌شده',
            self::ATTENTION_EXCEEDS_PROFIT => 'بیشتر از سود سفارش',
        ];
    }

    /**
     * کوئری کمیسیون‌های این نماینده با ستون‌های محاسبه‌شده‌ی `order_status` و `order_profit`
     * و روابط Eager-load‌شده‌ی معرف، مشتریِ معرفی‌شده و سفارش/محصول.
     */
    public function query(Reseller $reseller): Builder
    {
        $order = fn () => DB::table('orders')->whereColumn('orders.id', 'commissions.order_id');

        return Commission::query()
            ->select('commissions.*')
            ->whereExists($order()->where('orders.reseller_id', $reseller->id))
            ->selectSub($order()->select('orders.status'), 'order_status')
            ->selectSub($order()->selectRaw('coalesce(orders.customers_price, 0) - coalesce(orders.reseller_price, 0)'), 'order_profit')
            ->with([
                'referrer:id,full_name,email,telegram_id',
                'referredUser:id,full_name,email,telegram_id',
                'order:id,product_id,status,customers_price,reseller_price',
                'order.product:id,name',
            ]);
    }

    /** یک کمیسیونِ همین نماینده؛ متعلق به دیگری یا ناموجود ⇒ null (هرگز داده‌ی دیگران برنمی‌گردد). */
    public function find(Reseller $reseller, int|string $id): ?Commission
    {
        return ctype_digit((string) $id) ? $this->query($reseller)->whereKey((int) $id)->first() : null;
    }

    /** فیلتر نوع؛ مقدار ناشناخته (URL دست‌ساز) ⇒ بدون فیلتر، نه خطا. */
    public function applyKind(Builder $query, mixed $kind): Builder
    {
        return match ($kind) {
            self::KIND_COMMISSION => $query->where('commissions.type', Commission::TYPE_ONGOING),
            self::KIND_BONUS => $query->where('commissions.type', Commission::TYPE_FIRST_PURCHASE),
            default => $query,
        };
    }

    /** فیلتر وضعیت پرداخت؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyStatus(Builder $query, mixed $status): Builder
    {
        return is_string($status) && array_key_exists($status, Commission::statusLabels())
            ? $query->where('commissions.status', $status)
            : $query;
    }

    /** فیلتر «نیازمند توجه»؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyAttention(Builder $query, mixed $flag): Builder
    {
        return match ($flag) {
            self::ATTENTION_REFUNDED => $query->whereRaw(self::ORDER_REFUNDED_SQL),
            self::ATTENTION_EXCEEDS_PROFIT => $query
                ->where('commissions.type', Commission::TYPE_ONGOING)
                ->whereRaw('commissions.amount > '.self::ORDER_PROFIT_SQL),
            default => $query,
        };
    }

    /**
     * فیلتر بازه‌ی زمانی پرداخت (همان بازه‌های B5.1؛ نیمه‌باز). مقدار ناشناخته ⇒ بدون فیلتر
     * (برخلاف داشبورد که به ۳۰ روز برمی‌گردد: فیلتر جدول «همه» هم معنا دارد).
     */
    public function applyPeriod(Builder $query, mixed $period, ?CarbonInterface $now = null): Builder
    {
        $period = is_string($period) ? DashboardPeriod::tryFrom($period) : null;

        if (! $period) {
            return $query;
        }

        [$from, $to] = $period->range($now ?? CarbonImmutable::now());

        return $query->where('commissions.created_at', '>=', $from)->where('commissions.created_at', '<', $to);
    }

    /**
     * جمع‌های همان Query فیلترشده‌ی جدول (جست‌وجو، فیلتر و مرتب‌سازی اثر دارند؛ ترتیب و ستون‌های اضافه حذف می‌شوند).
     * تعداد کوئری ثابت و برابر یک است.
     */
    public function totals(Builder $filtered): CommissionTotals
    {
        $row = $this->aggregateBase($filtered)->selectRaw(implode(', ', [
            $this->countWhen("commissions.type = 'ongoing_commission'", 'commission_count'),
            $this->sumWhen("commissions.type = 'ongoing_commission'", 'commissions.amount', 'commission_amount'),
            $this->countWhen("commissions.type = 'first_purchase_bonus'", 'bonus_count'),
            $this->sumWhen("commissions.type = 'first_purchase_bonus'", 'commissions.amount', 'bonus_amount'),
            'count(distinct commissions.referrer_id) as referrers',
            'count(distinct commissions.referred_user_id) as referred_customers',
            $this->countWhen("commissions.status = 'pending'", 'pending_count'),
            $this->sumWhen("commissions.status = 'pending'", 'commissions.amount', 'pending_amount'),
            $this->countWhen(self::ORDER_REFUNDED_SQL, 'refunded_count'),
            $this->sumWhen(self::ORDER_REFUNDED_SQL, 'commissions.amount', 'refunded_amount'),
            $this->countWhen("commissions.type = 'ongoing_commission' and commissions.amount > ".self::ORDER_PROFIT_SQL, 'exceeds_count'),
            $this->sumWhen("commissions.type = 'ongoing_commission'", self::ORDER_PROFIT_SQL, 'profit_base'),
            $this->countWhen(self::ORDER_REFUNDED_SQL." or (commissions.type = 'ongoing_commission' and commissions.amount > ".self::ORDER_PROFIT_SQL.')', 'attention_count'),
        ]))->first();

        return new CommissionTotals(
            commissionCount: (int) $row->commission_count,
            commissionAmount: (int) $row->commission_amount,
            bonusCount: (int) $row->bonus_count,
            bonusAmount: (int) $row->bonus_amount,
            referrers: (int) $row->referrers,
            referredCustomers: (int) $row->referred_customers,
            pendingCount: (int) $row->pending_count,
            pendingAmount: (int) $row->pending_amount,
            refundedOrderCount: (int) $row->refunded_count,
            refundedOrderAmount: (int) $row->refunded_amount,
            exceedsProfitCount: (int) $row->exceeds_count,
            profitOnCommissionedOrders: (int) $row->profit_base,
            attentionCount: (int) $row->attention_count,
        );
    }

    /**
     * پردرآمدترین معرف‌ها (بر اساس مجموع پرداختی) روی همان Query فیلترشده؛ دو کوئری ثابت (گروه‌بندی + نام کاربران).
     *
     * @return list<TopReferrer>
     */
    public function topReferrers(Builder $filtered, int $limit = self::TOP_REFERRERS_LIMIT): array
    {
        $rows = $this->aggregateBase($filtered)
            ->selectRaw(implode(', ', [
                'commissions.referrer_id as referrer_id',
                $this->countWhen("commissions.type = 'ongoing_commission'", 'commission_count'),
                $this->sumWhen("commissions.type = 'ongoing_commission'", 'commissions.amount', 'commission_amount'),
                $this->sumWhen("commissions.type = 'first_purchase_bonus'", 'commissions.amount', 'bonus_amount'),
                'coalesce(sum(commissions.amount), 0) as total_amount',
                'count(distinct commissions.referred_user_id) as referred_customers',
                'max(commissions.created_at) as last_paid_at',
            ]))
            ->groupBy('commissions.referrer_id')
            ->orderByDesc('total_amount')
            ->orderBy('commissions.referrer_id')
            ->limit(max(1, $limit))
            ->get();

        $users = User::query()
            ->select('id', 'full_name', 'email', 'telegram_id')
            ->whereIn('id', $rows->pluck('referrer_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($users): TopReferrer {
            $user = $users->get($row->referrer_id);

            return new TopReferrer(
                userId: (int) $row->referrer_id,
                name: $user?->full_name ?: ($user?->email ?: 'کاربر #'.$row->referrer_id),
                contact: $user ? collect([$user->email, $user->telegram_id ? 'تلگرام: '.$user->telegram_id : null])->filter()->first() : null,
                commissionCount: (int) $row->commission_count,
                commissionAmount: (int) $row->commission_amount,
                bonusAmount: (int) $row->bonus_amount,
                referredCustomers: (int) $row->referred_customers,
                lastPaidAt: $row->last_paid_at ? CarbonImmutable::parse($row->last_paid_at) : null,
            );
        })->all();
    }

    /** شرایط فعلی سراسری (فقط‌خواندنی؛ نبودِ رکورد ⇒ همه صفر و هیچ‌چیز ساخته نمی‌شود). */
    public function terms(): CommissionTerms
    {
        $settings = AffiliateSetting::query()->first();

        return new CommissionTerms(
            percent: (string) ($settings?->commission_percent ?? '0.00'),
            validityDays: (int) ($settings?->commission_validity_days ?? 0),
            referrerBonus: (int) ($settings?->referrer_bonus_amount ?? 0),
            customerBonus: (int) ($settings?->customer_bonus_amount ?? 0),
        );
    }
}
