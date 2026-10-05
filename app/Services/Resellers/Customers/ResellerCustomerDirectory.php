<?php

namespace App\Services\Resellers\Customers;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * مدیریت مشتریان نماینده (B5.2): فهرست، بخش‌بندی، خلاصه و پرونده‌ی هر مشتری — از یک منبع واحد در Core.
 *
 * قواعد (هم‌راستا با B5.1):
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد و نمی‌نویسد، حتی Wallet خالی (WalletService::balanceIn در نبودِ Wallet
 *    آن را می‌ساخت؛ باز کردن یک صفحه نباید در DB بنویسد، پس موجودی مستقیم و با Subquery خوانده می‌شود).
 *  - جداسازی: «مشتری این نماینده» = داشتن CustomerAccount در فروشگاه او (Rule 12). همه‌ی عددها (سفارش، خرج،
 *    سرویس، موجودی) فقط از همین فروشگاه‌اند؛ مشتری‌ای که جای دیگر هم خرید کرده آن خرید را اینجا نمی‌بیند.
 *  - بدون N+1: ستون‌های محاسبه‌شده Subquery هستند؛ تعداد کوئری فهرست به تعداد مشتری وابسته نیست.
 *  - همه‌ی مبالغ int (Minor Unit)؛ قالب‌بندی و URL کار Channel است.
 *  - تعریف «فروش» همان B5.1 است (paid و account_created) و سرویس آزمایشی (is_test) سرویس فروش نیست.
 */
class ResellerCustomerDirectory
{
    /** هم‌تعریف با داشبورد تا یک عدد در دو جا دو مقدار نشان ندهد */
    public const REVENUE_STATUSES = ResellerDashboardService::REVENUE_STATUSES;

    public const EXPIRING_DAYS = ResellerDashboardService::EXPIRING_DAYS;

    public const PROFILE_SERVICES_LIMIT = 50;

    public const PROFILE_ORDERS_LIMIT = 10;

    /** وضعیت‌های قابل‌فیلتر عضویت (ستون customer_accounts.status) */
    public const MEMBERSHIP_STATUSES = ['active', 'disabled', 'blocked'];

    /**
     * کوئری مشتریان این نماینده (همه‌ی وضعیت‌های عضویت) با ستون‌های محاسبه‌شده:
     * membership_status، joined_at، wallet_balance، orders_count، total_spent، last_order_at،
     * active_services_count، next_expiry_at.
     */
    public function query(Reseller $reseller, ?CarbonInterface $now = null): Builder
    {
        $now = $this->now($now);

        return User::query()
            ->select('users.*')
            ->whereExists($this->membership($reseller)->toBase())
            ->selectSub($this->membership($reseller)->select('status'), 'membership_status')
            ->selectSub($this->membership($reseller)->select('created_at'), 'joined_at')
            ->selectSub($this->wallet($reseller)->select('balance'), 'wallet_balance')
            ->selectSub($this->settledOrders($reseller)->selectRaw('count(*)'), 'orders_count')
            ->selectSub($this->settledOrders($reseller)->selectRaw('coalesce(sum(customers_price), 0)'), 'total_spent')
            ->selectSub($this->settledOrders($reseller)->selectRaw('max(created_at)'), 'last_order_at')
            ->selectSub($this->activeServices($reseller, $now)->selectRaw('count(*)'), 'active_services_count')
            ->selectSub($this->activeServices($reseller, $now)->selectRaw('min(expires_at)'), 'next_expiry_at');
    }

    /** فیلتر بخش (CustomerSegment) روی کوئریِ query() یا هر کوئری User */
    public function applySegment(Builder $query, Reseller $reseller, CustomerSegment $segment, ?CarbonInterface $now = null): Builder
    {
        $now = $this->now($now);

        return match ($segment) {
            CustomerSegment::ActiveService => $query->whereExists($this->activeServices($reseller, $now)->toBase()),
            CustomerSegment::Expiring => $query->whereExists(
                $this->activeServices($reseller, $now)
                    ->whereNotNull('accounts.expires_at')
                    ->where('accounts.expires_at', '<=', $now->addDays(self::EXPIRING_DAYS))
                    ->toBase()
            ),
            CustomerSegment::NeedsRenewal => $query
                ->whereNotExists($this->activeServices($reseller, $now)->toBase())
                ->whereExists($this->lapsedServices($reseller, $now)->toBase()),
            CustomerSegment::NeverBought => $query->whereNotExists($this->settledOrders($reseller)->toBase()),
            CustomerSegment::HasBalance => $query->whereExists($this->wallet($reseller)->where('wallets.balance', '>', 0)->toBase()),
        };
    }

    /** فیلتر وضعیت عضویت؛ مقدار ناشناخته ⇒ بدون فیلتر */
    public function applyMembershipStatus(Builder $query, Reseller $reseller, mixed $status): Builder
    {
        if (! is_string($status) || ! in_array($status, self::MEMBERSHIP_STATUSES, true)) {
            return $query;
        }

        return $query->whereExists($this->membership($reseller)->where('customer_accounts.status', $status)->toBase());
    }

    /** خلاصه‌ی فهرست؛ تعداد کوئری ثابت است و تعریف بخش‌ها همان فیلتر جدول است. */
    public function summary(Reseller $reseller, ?CarbonInterface $now = null): CustomerDirectorySummary
    {
        $now = $this->now($now);

        $members = fn (): Builder => User::query()->whereExists($this->membership($reseller)->toBase());
        $segment = fn (CustomerSegment $s): int => $this->applySegment($members(), $reseller, $s, $now)->count();

        $total = $members()->count();
        $active = $this->applyMembershipStatus($members(), $reseller, 'active')->count();

        return new CustomerDirectorySummary(
            total: $total,
            active: $active,
            inactive: $total - $active,
            newInLast30Days: $members()
                ->whereExists($this->membership($reseller)->where('customer_accounts.created_at', '>=', $now->subDays(30))->toBase())
                ->count(),
            withActiveService: $segment(CustomerSegment::ActiveService),
            expiring: $segment(CustomerSegment::Expiring),
            needsRenewal: $segment(CustomerSegment::NeedsRenewal),
            neverBought: $segment(CustomerSegment::NeverBought),
            totalWalletBalance: (int) Wallet::query()
                ->where('scope_key', Wallet::scopeKeyFor('reseller', $reseller->id))
                ->whereIn('user_id', $members()->select('users.id'))
                ->where('balance', '>', 0)
                ->sum('balance'),
        );
    }

    /**
     * پرونده‌ی یک مشتری. اگر این کاربر عضو فروشگاه این نماینده نباشد null برمی‌گردد (هرگز داده‌ی دیگران نشان داده نمی‌شود).
     */
    public function profile(Reseller $reseller, User $user): ?CustomerProfile
    {
        $membership = CustomerAccount::query()
            ->where('user_id', $user->getKey())
            ->where('store_type', 'reseller')
            ->where('reseller_id', $reseller->id)
            ->first();

        if (! $membership) {
            return null;
        }

        $orders = fn (): Builder => Order::query()
            ->where('user_id', $user->getKey())
            ->where('reseller_id', $reseller->id);

        $totals = $orders()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(case when renews_account_id is null then 0 else 1 end), 0) as renewals')
            ->selectRaw('coalesce(sum(customers_price), 0) as revenue')
            ->selectRaw('coalesce(sum(coalesce(customers_price, 0) - coalesce(reseller_price, 0)), 0) as profit')
            ->selectRaw('max(created_at) as last_order_at')
            ->first();

        $services = Account::query()
            ->where('user_id', $user->getKey())
            ->where('customer_account_id', $membership->id)
            ->where('is_test', false)
            ->with('product:id,name')
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderByDesc('expires_at')
            ->limit(self::PROFILE_SERVICES_LIMIT)
            ->get();

        return new CustomerProfile(
            user: $user,
            membershipStatus: (string) $membership->status,
            joinedAt: $membership->created_at,
            walletBalance: (int) (Wallet::query()
                ->where('user_id', $user->getKey())
                ->where('scope_key', Wallet::scopeKeyFor('reseller', $reseller->id))
                ->value('balance') ?? 0),
            orders: (int) ($totals->orders ?? 0),
            renewals: (int) ($totals->renewals ?? 0),
            revenue: (int) ($totals->revenue ?? 0),
            profit: (int) ($totals->profit ?? 0),
            lastOrderAt: ($totals->last_order_at ?? null) ? CarbonImmutable::parse($totals->last_order_at) : null,
            services: $services,
            recentOrders: $orders()
                ->with('product:id,name')
                ->latest('id')
                ->limit(self::PROFILE_ORDERS_LIMIT)
                ->get(),
        );
    }

    /* ------------------------------------------------------------------
     | داخلی — همه Correlated به users.id و Scope‌شده به همین نماینده
     * ----------------------------------------------------------------- */

    private function now(?CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now());
    }

    private function membership(Reseller $reseller): Builder
    {
        return CustomerAccount::query()
            ->whereColumn('customer_accounts.user_id', 'users.id')
            ->where('customer_accounts.store_type', 'reseller')
            ->where('customer_accounts.reseller_id', $reseller->id);
    }

    private function wallet(Reseller $reseller): Builder
    {
        return Wallet::query()
            ->whereColumn('wallets.user_id', 'users.id')
            ->where('wallets.scope_key', Wallet::scopeKeyFor('reseller', $reseller->id));
    }

    private function settledOrders(Reseller $reseller): Builder
    {
        return Order::query()
            ->whereColumn('orders.user_id', 'users.id')
            ->where('orders.reseller_id', $reseller->id)
            ->whereIn('orders.status', self::REVENUE_STATUSES);
    }

    /** سرویس‌های فروش‌رفته (نه آزمایشی) به مشتریِ همین فروشگاه */
    private function services(Reseller $reseller): Builder
    {
        return Account::query()
            ->whereColumn('accounts.user_id', 'users.id')
            ->where('accounts.is_test', false)
            ->whereIn('accounts.customer_account_id', CustomerAccount::query()
                ->where('store_type', 'reseller')
                ->where('reseller_id', $reseller->id)
                ->select('id'));
    }

    private function activeServices(Reseller $reseller, CarbonImmutable $now): Builder
    {
        return $this->services($reseller)
            ->where('accounts.status', 'active')
            ->where(fn ($q) => $q->whereNull('accounts.expires_at')->orWhere('accounts.expires_at', '>', $now));
    }

    /** سرویسِ تمام‌شده: status=expired یا هنوز active ولی تاریخ گذشته (هم‌تعریف با Account::scopeLapsed) */
    private function lapsedServices(Reseller $reseller, CarbonImmutable $now): Builder
    {
        return $this->services($reseller)->where(fn ($q) => $q
            ->where('accounts.status', 'expired')
            ->orWhere(fn ($q2) => $q2->where('accounts.status', 'active')->where('accounts.expires_at', '<=', $now)));
    }
}
