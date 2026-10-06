<?php

namespace App\Services\Resellers\Marketing;

use App\Models\Broadcast;
use App\Models\Commission;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\Concerns\AggregatesFilteredQuery;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * مرکز بازاریابی نماینده (B5.6): معرفی (Referral) و کمپین‌های پیام — از یک منبع واحد در Core.
 *
 * قواعد (هم‌راستا با B5.1–B5.5):
 *  - فقط‌خواندنی: هیچ رکورد/Walletی نمی‌سازد؛ هیچ پاداش/کمیسیونی محاسبه یا پرداخت نمی‌کند (Core: ReferralService/CommissionService).
 *    ارسال پیام همگانی همچنان فقط از صفحه‌ی «پیام همگانی» است.
 *  - Referral = Attribution (Master §11): «عضو معرفی‌شده» یعنی عضو (CustomerAccount) همین فروشگاه که `users.referrer_id` دارد.
 *    معرف لازم نیست عضو همین فروشگاه باشد (حساب او هنگام پاداش Lazy ساخته می‌شود). Referral سراسری است؛ این صفحه فقط اعضای همین فروشگاه را می‌شمارد.
 *  - «خرید واقعی» = وضعیت‌های ReferralService::isFirstPurchase (paid/provisioning/account_created/provision_failed)؛ سفارش failed/refunded نه.
 *  - کمیسیون و پاداش معرفی همیشه جدا (Master §11) و فقط از سفارش‌های همین فروشگاه.
 *  - کوپن/تخفیف: موتور Discount در کد وجود ندارد (Master §16 DS5: SPECIFIED فقط) و این‌جا ساخته نمی‌شود.
 *  - بدون N+1؛ جمع‌ها یک کوئری Aggregate روی همان Query فیلترشده‌ی جدول. همه int.
 */
class ResellerMarketingCenter
{
    use AggregatesFilteredQuery;

    /** خرید واقعی (هم‌تعریف ReferralService::isFirstPurchase) */
    public const PURCHASE_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_PROVISIONING,
        Order::STATUS_ACCOUNT_CREATED,
        Order::STATUS_PROVISION_FAILED,
    ];

    public const TOP_LIMIT = 5;

    public const STATE_CONVERTED = 'converted';

    public const STATE_WAITING = 'waiting';

    /** @return array<string, string> */
    public static function stateLabels(): array
    {
        return [self::STATE_CONVERTED => 'خرید کرده', self::STATE_WAITING => 'هنوز خرید نکرده'];
    }

    /** @return array<string, string> */
    public static function campaignStatusLabels(): array
    {
        return ['queued' => 'در صف', 'sending' => 'در حال ارسال', 'completed' => 'کامل‌شده', 'failed' => 'ناموفق'];
    }

    /* -------------------------------------------------------------- Referral */

    /**
     * اعضای معرفی‌شده‌ی این فروشگاه (هر ردیف یک CustomerAccount) با ستون‌های محاسبه‌شده:
     * `referrer_user_id`, `purchases` (تعداد خرید واقعی), `spent` (فروش قطعی), `first_purchase_at`.
     * Eager-load: کاربر و معرفش.
     */
    public function referralQuery(Reseller $reseller): Builder
    {
        $orders = fn () => DB::table('orders')
            ->whereColumn('orders.customer_account_id', 'customer_accounts.id')
            ->where('orders.reseller_id', $reseller->id)
            ->whereIn('orders.status', self::PURCHASE_STATUSES);

        return CustomerAccount::query()
            ->select('customer_accounts.*')
            ->where('customer_accounts.store_type', 'reseller')
            ->where('customer_accounts.reseller_id', $reseller->id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('users')
                ->whereColumn('users.id', 'customer_accounts.user_id')
                ->whereNotNull('users.referrer_id')
                ->whereNull('users.deleted_at'))
            ->selectSub(DB::table('users')->select('users.referrer_id')->whereColumn('users.id', 'customer_accounts.user_id'), 'referrer_user_id')
            ->selectSub($orders()->selectRaw('count(*)'), 'purchases')
            ->selectSub($orders()->selectRaw('coalesce(sum(orders.customers_price), 0)'), 'spent')
            ->selectSub($orders()->selectRaw('min(orders.created_at)'), 'first_purchase_at')
            ->with(['user:id,full_name,email,telegram_id,referrer_id', 'user.referrer:id,full_name,email,telegram_id']);
    }

    /** فیلتر وضعیت تبدیل؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyState(Builder $query, mixed $state): Builder
    {
        $has = fn () => DB::table('orders')
            ->whereColumn('orders.customer_account_id', 'customer_accounts.id')
            ->whereIn('orders.status', self::PURCHASE_STATUSES);

        return match ($state) {
            self::STATE_CONVERTED => $query->whereExists($has()),
            self::STATE_WAITING => $query->whereNotExists($has()),
            default => $query,
        };
    }

    /** بازه‌ی عضویت (نیمه‌باز؛ همان بازه‌های B5.1)؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyJoinedPeriod(Builder $query, mixed $period, ?CarbonInterface $now = null): Builder
    {
        $period = is_string($period) ? DashboardPeriod::tryFrom($period) : null;

        if (! $period) {
            return $query;
        }

        [$from, $to] = $period->range($now ?? CarbonImmutable::now());

        return $query->where('customer_accounts.created_at', '>=', $from)->where('customer_accounts.created_at', '<', $to);
    }

    /** جمع‌های همان Query فیلترشده‌ی جدول؛ یک کوئری. */
    public function referralTotals(Reseller $reseller, Builder $filtered): ReferralTotals
    {
        $row = $this->aggregateBase($filtered)->selectRaw(implode(', ', [
            'count(*) as invited',
            'count(distinct (select users.referrer_id from users where users.id = customer_accounts.user_id)) as referrers',
            $this->countWhen('exists (select 1 from orders o where o.customer_account_id = customer_accounts.id and o.status in ('.$this->statusList().'))', 'converted'),
            'coalesce(sum((select coalesce(sum(o2.customers_price), 0) from orders o2 where o2.customer_account_id = customer_accounts.id and o2.reseller_id = '.(int) $reseller->id.' and o2.status in ('.$this->statusList().'))), 0) as revenue',
        ]))->first();

        return new ReferralTotals(
            invited: (int) $row->invited,
            converted: (int) $row->converted,
            referrers: (int) $row->referrers,
            revenue: (int) $row->revenue,
        );
    }

    /**
     * پرمعرفی‌ترین معرف‌ها روی همان Query فیلترشده؛ سه کوئری ثابت (گروه‌بندی، نام‌ها، درآمدها).
     *
     * @return list<TopInviter>
     */
    public function topInviters(Reseller $reseller, Builder $filtered, int $limit = self::TOP_LIMIT): array
    {
        $rows = $this->aggregateBase($filtered)
            ->selectRaw('(select users.referrer_id from users where users.id = customer_accounts.user_id) as referrer_user_id')
            ->selectRaw('count(*) as invited')
            ->selectRaw($this->countWhen('exists (select 1 from orders o where o.customer_account_id = customer_accounts.id and o.status in ('.$this->statusList().'))', 'converted'))
            ->groupBy('referrer_user_id')
            ->orderByDesc('invited')
            ->orderBy('referrer_user_id')
            ->limit(max(1, $limit))
            ->get();

        $ids = $rows->pluck('referrer_user_id');

        $users = User::query()->select('id', 'full_name', 'email', 'telegram_id')->whereIn('id', $ids)->get()->keyBy('id');

        $earned = Commission::query()
            ->whereIn('commissions.referrer_id', $ids)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('orders')
                ->whereColumn('orders.id', 'commissions.order_id')
                ->where('orders.reseller_id', $reseller->id))
            ->selectRaw('commissions.referrer_id as rid')
            ->selectRaw("coalesce(sum(case when commissions.type = '".Commission::TYPE_ONGOING."' then commissions.amount else 0 end), 0) as commission")
            ->selectRaw("coalesce(sum(case when commissions.type = '".Commission::TYPE_FIRST_PURCHASE."' then commissions.amount else 0 end), 0) as bonus")
            ->groupBy('commissions.referrer_id')
            ->get()
            ->keyBy('rid');

        return $rows->map(function ($row) use ($users, $earned): TopInviter {
            $user = $users->get($row->referrer_user_id);
            $money = $earned->get($row->referrer_user_id);

            return new TopInviter(
                userId: (int) $row->referrer_user_id,
                name: $user?->full_name ?: ($user?->email ?: 'کاربر #'.$row->referrer_user_id),
                contact: $user ? collect([$user->email, $user->telegram_id ? 'تلگرام: '.$user->telegram_id : null])->filter()->first() : null,
                invited: (int) $row->invited,
                converted: (int) $row->converted,
                commissionEarned: (int) ($money->commission ?? 0),
                bonusEarned: (int) ($money->bonus ?? 0),
            );
        })->all();
    }

    /* -------------------------------------------------------------- Campaigns */

    /** کمپین‌های پیام این نماینده (reseller_id همین نماینده)؛ پیام‌های ادمین اصلی (reseller_id تهی) هرگز نمی‌آیند. */
    public function campaignQuery(Reseller $reseller): Builder
    {
        return Broadcast::query()->select('broadcasts.*')->where('broadcasts.reseller_id', $reseller->id);
    }

    /** فیلتر وضعیت؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyCampaignStatus(Builder $query, mixed $status): Builder
    {
        return is_string($status) && array_key_exists($status, self::campaignStatusLabels())
            ? $query->where('broadcasts.status', $status)
            : $query;
    }

    /** بازه‌ی ایجاد (نیمه‌باز)؛ مقدار ناشناخته ⇒ بدون فیلتر. */
    public function applyCampaignPeriod(Builder $query, mixed $period, ?CarbonInterface $now = null): Builder
    {
        $period = is_string($period) ? DashboardPeriod::tryFrom($period) : null;

        if (! $period) {
            return $query;
        }

        [$from, $to] = $period->range($now ?? CarbonImmutable::now());

        return $query->where('broadcasts.created_at', '>=', $from)->where('broadcasts.created_at', '<', $to);
    }

    public function campaignTotals(Builder $filtered): CampaignTotals
    {
        $row = $this->aggregateBase($filtered)->selectRaw(implode(', ', [
            'count(*) as n',
            'coalesce(sum(broadcasts.total_recipients), 0) as recipients',
            'coalesce(sum(broadcasts.sent_count), 0) as sent',
            'coalesce(sum(broadcasts.failed_count), 0) as failed',
            $this->countWhen("broadcasts.status in ('queued', 'sending')", 'active'),
        ]))->first();

        return new CampaignTotals((int) $row->n, (int) $row->recipients, (int) $row->sent, (int) $row->failed, (int) $row->active);
    }

    private function statusList(): string
    {
        return "'".implode("','", self::PURCHASE_STATUSES)."'";
    }
}
