<?php

namespace App\Services\Admin\Customers;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * نمای سراسری مشتریان ادمین (B7.2): فهرست، بخش‌بندی، خلاصه و پرونده‌ی هر مشتری روی «همه‌ی فروشگاه‌ها» —
 * از یک منبع واحد در Core. هم‌خانواده‌ی `ResellerCustomerDirectory` (B5.2) ولی بدون Scope به یک نماینده.
 *
 * قواعد:
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد و نمی‌نویسد (حتی Wallet خالی؛ موجودی با Subquery خوانده می‌شود).
 *  - R2/R8: User هویت مرکزی است ولی CustomerAccountها Merge نمی‌شوند. پرونده، هر فروشگاه را «جدا» نشان می‌دهد؛
 *    جمع کل فقط نمایشی است.
 *  - بدون N+1: ستون‌های محاسبه‌شده Subquery هستند؛ تعداد کوئری فهرست/پرونده به تعداد رکوردها وابسته نیست.
 *  - همه‌ی مبالغ int (Minor Unit)؛ قالب‌بندی و URL کار Channel است.
 *  - تعریف «فروش» همان B7.1 است (paid و account_created؛ اکانت تست بیرون)، «خرج مشتری» = COALESCE(main_price, customers_price, 0).
 */
class GlobalCustomerDirectory
{
    /** هم‌تعریف با داشبورد اجرایی تا یک عدد در دو جا دو مقدار نشان ندهد */
    public const REVENUE_STATUSES = ExecutiveDashboardService::REVENUE_STATUSES;

    public const EXPIRING_DAYS = ExecutiveDashboardService::EXPIRING_DAYS;

    public const PROFILE_SERVICES_LIMIT = 50;

    public const PROFILE_ORDERS_LIMIT = 10;

    public const PROFILE_PAYMENTS_LIMIT = 8;

    public const PROFILE_TICKETS_LIMIT = 5;

    public const PROFILE_WALLET_TRANSACTIONS_LIMIT = 10;

    public const MAIN = 'main';

    /** نتیجه‌ی `summary()` در همین Request (Instance در Container به‌صورت `scoped` ثبت می‌شود) */
    private ?GlobalCustomerSummary $summaryMemo = null;

    /**
     * کوئری همه‌ی کاربران (حذف‌نرم‌ها نمی‌آیند) با ستون‌های محاسبه‌شده:
     * stores_count، main_balance، stores_balance، settled_orders_count، total_spent، last_order_at،
     * active_services_count، next_expiry_at، open_tickets_count، google_identities_count.
     */
    public function query(?CarbonInterface $now = null): Builder
    {
        $now = $this->now($now);

        return User::query()
            ->select('users.*')
            ->selectSub($this->memberships()->selectRaw('count(*)'), 'stores_count')
            ->selectSub($this->wallets()->where('wallets.scope_key', Wallet::scopeKeyFor('main', null))->select('balance'), 'main_balance')
            ->selectSub($this->wallets()->where('wallets.scope_key', '!=', Wallet::scopeKeyFor('main', null))->selectRaw('coalesce(sum(wallets.balance), 0)'), 'stores_balance')
            ->selectSub($this->settledOrders()->selectRaw('count(*)'), 'settled_orders_count')
            ->selectSub($this->settledOrders()->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.customers_price, 0)), 0)'), 'total_spent')
            ->selectSub($this->settledOrders()->selectRaw('max(orders.created_at)'), 'last_order_at')
            ->selectSub($this->activeServices($now)->selectRaw('count(*)'), 'active_services_count')
            ->selectSub($this->activeServices($now)->selectRaw('min(accounts.expires_at)'), 'next_expiry_at')
            ->selectSub($this->openTickets()->selectRaw('count(*)'), 'open_tickets_count')
            ->selectSub($this->googleIdentities()->selectRaw('count(*)'), 'google_identities_count');
    }

    /** فیلتر بخش؛ تنها تعریف بخش‌ها همین‌جاست. */
    public function applySegment(Builder $query, GlobalCustomerSegment $segment, ?CarbonInterface $now = null): Builder
    {
        $now = $this->now($now);

        return match ($segment) {
            GlobalCustomerSegment::ActiveService => $query->whereExists($this->activeServices($now)->toBase()),
            GlobalCustomerSegment::Expiring => $query->whereExists(
                $this->activeServices($now)
                    ->whereNotNull('accounts.expires_at')
                    ->where('accounts.expires_at', '<=', $now->addDays(self::EXPIRING_DAYS))
                    ->toBase()
            ),
            GlobalCustomerSegment::NeedsRenewal => $query
                ->whereNotExists($this->activeServices($now)->toBase())
                ->whereExists($this->lapsedServices($now)->toBase()),
            GlobalCustomerSegment::NeverBought => $query->whereNotExists($this->settledOrders()->toBase()),
            GlobalCustomerSegment::HasBalance => $query->whereExists($this->wallets()->where('wallets.balance', '>', 0)->toBase()),
            GlobalCustomerSegment::MultiStore => $this->applyMultiStore($query),
            GlobalCustomerSegment::OpenTicket => $query->whereExists($this->openTickets()->toBase()),
        };
    }

    /** فیلتر هویت متصل */
    public function applyIdentity(Builder $query, GlobalCustomerIdentity $identity): Builder
    {
        return match ($identity) {
            GlobalCustomerIdentity::Google => $query->whereExists($this->googleIdentities()->toBase()),
            GlobalCustomerIdentity::Telegram => $query->whereNotNull('users.telegram_id'),
            GlobalCustomerIdentity::GoogleAndTelegram => $query
                ->whereNotNull('users.telegram_id')
                ->whereExists($this->googleIdentities()->toBase()),
            GlobalCustomerIdentity::EmailVerified => $query->whereNotNull('users.email_verified_at'),
            GlobalCustomerIdentity::EmailUnverified => $query->whereNotNull('users.email')->whereNull('users.email_verified_at'),
        };
    }

    /** فیلتر عضویت در یک فروشگاه: `main` یا `reseller:{id}`؛ مقدار ناشناخته ⇒ بدون فیلتر */
    public function applyStore(Builder $query, mixed $scopeKey): Builder
    {
        if (! self::isValidScopeKey($scopeKey)) {
            return $query;
        }

        return $query->whereExists($this->memberships()->where('customer_accounts.scope_key', $scopeKey)->toBase());
    }

    public static function isValidScopeKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(main|reseller:[1-9][0-9]{0,17})$/', $value) === 1;
    }

    /** خلاصه‌ی فهرست؛ تعداد کوئری ثابت است و تعریف بخش‌ها همان فیلتر جدول است. */
    public function summary(?CarbonInterface $now = null): GlobalCustomerSummary
    {
        // بدون `$now` صریح (مسیر پنل): در طول یک Request فقط یک بار محاسبه می‌شود تا ویجت خلاصه و شمارنده‌ی
        // تب‌ها دو بار کوئری سنگین نزنند. با `$now` صریح (تست/گزارش) همیشه تازه محاسبه می‌شود.
        if ($now === null && $this->summaryMemo !== null) {
            return $this->summaryMemo;
        }

        $memoize = $now === null;
        $now = $this->now($now);

        $users = fn (): Builder => User::query();
        $segment = fn (GlobalCustomerSegment $s): int => $this->applySegment($users(), $s, $now)->count();
        $identity = fn (GlobalCustomerIdentity $i): int => $this->applyIdentity($users(), $i)->count();

        $summary = new GlobalCustomerSummary(
            total: $users()->count(),
            newInLast30Days: $users()->where('users.created_at', '>=', $now->subDays(30))->count(),
            inactive: $users()->where('users.status', '!=', 'active')->count(),
            withActiveService: $segment(GlobalCustomerSegment::ActiveService),
            expiring: $segment(GlobalCustomerSegment::Expiring),
            needsRenewal: $segment(GlobalCustomerSegment::NeedsRenewal),
            neverBought: $segment(GlobalCustomerSegment::NeverBought),
            withBalance: $segment(GlobalCustomerSegment::HasBalance),
            multiStore: $segment(GlobalCustomerSegment::MultiStore),
            withOpenTicket: $segment(GlobalCustomerSegment::OpenTicket),
            googleLinked: $identity(GlobalCustomerIdentity::Google),
            telegramLinked: $identity(GlobalCustomerIdentity::Telegram),
            unified: $identity(GlobalCustomerIdentity::GoogleAndTelegram),
            resellerOwners: $users()->whereExists(
                Reseller::query()->whereColumn('resellers.user_id', 'users.id')->toBase()
            )->count(),
            mainWalletBalance: (int) Wallet::query()
                ->where('scope_key', Wallet::scopeKeyFor('main', null))
                ->whereIn('user_id', $users()->select('users.id'))
                ->where('balance', '>', 0)
                ->sum('balance'),
        );

        if ($memoize) {
            $this->summaryMemo = $summary;
        }

        return $summary;
    }

    /**
     * پرونده‌ی سراسری یک مشتری. کوئری‌ها به تعداد فروشگاه/سفارش وابسته نیستند.
     */
    public function profile(User $user, ?CarbonInterface $now = null): GlobalCustomerProfile
    {
        $now = $this->now($now);
        $userId = $user->getKey();

        $memberships = CustomerAccount::withTrashed()->where('user_id', $userId)->get();
        $wallets = Wallet::query()->where('user_id', $userId)->get()->keyBy('scope_key');

        // فروش قطعی به تفکیک فروشگاه (reseller_id خالی = اصلی)
        $orderAgg = $this->orderQueryFor($userId)
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->groupBy('orders.reseller_id')
            ->selectRaw('orders.reseller_id as store_reseller_id')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(case when orders.renews_account_id is null then 0 else 1 end), 0) as renewals')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.customers_price, 0)), 0) as spent')
            ->selectRaw('coalesce(sum(coalesce(orders.main_price, orders.reseller_price, 0)), 0) as platform_revenue')
            ->selectRaw('max(orders.created_at) as last_order_at')
            ->get()
            ->keyBy(fn ($row) => Wallet::scopeKeyFor($row->store_reseller_id ? 'reseller' : 'main', $row->store_reseller_id ? (int) $row->store_reseller_id : null));

        // سرویس‌های فعال به تفکیک فروشگاه (از طریق CustomerAccount)
        $activeByScope = Account::query()
            ->join('customer_accounts', 'customer_accounts.id', '=', 'accounts.customer_account_id')
            ->where('accounts.user_id', $userId)
            ->where('accounts.is_test', false)
            ->where('accounts.status', 'active')
            ->where(fn ($q) => $q->whereNull('accounts.expires_at')->orWhere('accounts.expires_at', '>', $now))
            ->groupBy('customer_accounts.scope_key')
            ->selectRaw('customer_accounts.scope_key as scope_key, count(*) as total')
            ->pluck('total', 'scope_key');

        $lapsedCount = $this->lapsedServicesBase($now)->where('accounts.user_id', $userId)->count();

        $scopeKeys = collect([self::MAIN])
            ->merge($memberships->pluck('scope_key'))
            ->merge($wallets->keys())
            ->merge($orderAgg->keys())
            ->filter()
            ->unique()
            ->values();

        $resellerIds = $scopeKeys
            ->filter(fn (string $k) => str_starts_with($k, 'reseller:'))
            ->map(fn (string $k) => (int) substr($k, 9))
            ->all();

        $slugs = $resellerIds === []
            ? collect()
            : Reseller::query()->whereIn('id', $resellerIds)->pluck('slug', 'id');

        $stores = $scopeKeys
            ->map(function (string $key) use ($memberships, $wallets, $orderAgg, $activeByScope, $slugs): CustomerStoreRow {
                $resellerId = str_starts_with($key, 'reseller:') ? (int) substr($key, 9) : null;
                $membership = $memberships->firstWhere('scope_key', $key);
                $agg = $orderAgg->get($key);

                return new CustomerStoreRow(
                    scopeKey: $key,
                    resellerId: $resellerId,
                    resellerSlug: $resellerId ? ($slugs[$resellerId] ?? null) : null,
                    membershipStatus: $membership === null ? null : ($membership->trashed() ? 'removed' : (string) $membership->status),
                    joinedAt: $membership?->created_at,
                    walletBalance: (int) ($wallets->get($key)?->balance ?? 0),
                    orders: (int) ($agg->orders ?? 0),
                    spent: (int) ($agg->spent ?? 0),
                    platformRevenue: (int) ($agg->platform_revenue ?? 0),
                    activeServices: (int) ($activeByScope[$key] ?? 0),
                    lastOrderAt: ($agg->last_order_at ?? null) ? CarbonImmutable::parse($agg->last_order_at) : null,
                );
            })
            // فروشگاه اصلی همیشه می‌آید؛ فروشگاه‌های دیگر فقط اگر نشانی از حضور دارند
            ->filter(fn (CustomerStoreRow $r) => $r->isMain() || $r->membershipStatus !== null || $r->walletBalance !== 0 || $r->orders > 0)
            ->sortBy([
                fn (CustomerStoreRow $a, CustomerStoreRow $b) => ($b->isMain() <=> $a->isMain()),
                fn (CustomerStoreRow $a, CustomerStoreRow $b) => $b->spent <=> $a->spent,
            ])
            ->values();

        $services = Account::query()
            ->where('user_id', $userId)
            ->where('is_test', false)
            ->with('product:id,name')
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderByDesc('expires_at')
            ->limit(self::PROFILE_SERVICES_LIMIT)
            ->get();

        $membershipScopeById = $memberships->pluck('scope_key', 'id');
        $serviceStoreKeys = $services
            ->mapWithKeys(fn (Account $a) => [$a->id => (string) ($membershipScopeById[$a->customer_account_id] ?? self::MAIN)])
            ->all();

        $referrer = $user->referrer_id ? User::query()->find($user->referrer_id) : null;

        return new GlobalCustomerProfile(
            user: $user,
            google: UserIdentity::query()->where('user_id', $userId)->where('provider', UserIdentity::PROVIDER_GOOGLE)->first(),
            referrer: $referrer,
            referredCount: User::query()->where('referrer_id', $userId)->count(),
            ownedReseller: Reseller::query()->where('user_id', $userId)->first(),
            stores: $stores,
            orders: (int) $stores->sum('orders'),
            renewals: (int) $orderAgg->sum('renewals'),
            spent: (int) $stores->sum('spent'),
            platformRevenue: (int) $stores->sum('platformRevenue'),
            activeServices: (int) $stores->sum('activeServices'),
            lapsedServices: $lapsedCount,
            lastOrderAt: $stores->map->lastOrderAt->filter()->sortDesc()->first(),
            openTickets: Ticket::query()->where('user_id', $userId)->where('status', Ticket::STATUS_OPEN)->count(),
            services: $services,
            serviceStoreKeys: $serviceStoreKeys,
            recentOrders: $this->orderQueryFor($userId)
                ->with(['product:id,name', 'reseller:id,slug'])
                ->latest('orders.id')
                ->limit(self::PROFILE_ORDERS_LIMIT)
                ->get(),
            recentPayments: Payment::query()
                ->where('user_id', $userId)
                ->with('reseller:id,slug')
                ->latest('id')
                ->limit(self::PROFILE_PAYMENTS_LIMIT)
                ->get(),
            recentTickets: Ticket::query()
                ->where('user_id', $userId)
                ->with('reseller:id,slug')
                ->latest('id')
                ->limit(self::PROFILE_TICKETS_LIMIT)
                ->get(),
            recentWalletTransactions: WalletTransaction::query()
                ->whereIn('wallet_id', Wallet::query()->where('user_id', $userId)->select('id'))
                ->with('wallet:id,user_id,scope_key')
                ->latest('id')
                ->limit(self::PROFILE_WALLET_TRANSACTIONS_LIMIT)
                ->get(),
        );
    }

    /* ------------------------------------------------------------------
     | داخلی — همه Correlated به users.id
     * ----------------------------------------------------------------- */

    private function now(?CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? now());
    }

    private function applyMultiStore(Builder $query): Builder
    {
        $sub = $this->memberships()->selectRaw('count(*)');

        return $query->whereRaw('('.$sub->toSql().') >= ?', [...$sub->getBindings(), 2]);
    }

    /** عضویت‌های حذف‌نرم‌نشده */
    private function memberships(): Builder
    {
        return CustomerAccount::query()->whereColumn('customer_accounts.user_id', 'users.id');
    }

    private function wallets(): Builder
    {
        return Wallet::query()->whereColumn('wallets.user_id', 'users.id');
    }

    /** سفارش‌های قطعی (بدون اکانت تست) در همه‌ی فروشگاه‌ها */
    private function settledOrders(): Builder
    {
        return Order::query()
            ->whereColumn('orders.user_id', 'users.id')
            ->where('orders.sales_channel', '!=', 'test_account')
            ->whereIn('orders.status', self::REVENUE_STATUSES);
    }

    /** همه‌ی سفارش‌های واقعی یک کاربر (بدون اکانت تست؛ همه‌ی وضعیت‌ها) */
    private function orderQueryFor(int $userId): Builder
    {
        return Order::query()
            ->where('orders.user_id', $userId)
            ->where('orders.sales_channel', '!=', 'test_account');
    }

    private function services(): Builder
    {
        return Account::query()
            ->whereColumn('accounts.user_id', 'users.id')
            ->where('accounts.is_test', false);
    }

    private function activeServices(CarbonImmutable $now): Builder
    {
        return $this->services()
            ->where('accounts.status', 'active')
            ->where(fn ($q) => $q->whereNull('accounts.expires_at')->orWhere('accounts.expires_at', '>', $now));
    }

    /** سرویسِ تمام‌شده، Correlated به users.id */
    private function lapsedServices(CarbonImmutable $now): Builder
    {
        return $this->lapsedServicesBase($now)->whereColumn('accounts.user_id', 'users.id');
    }

    /** سرویسِ تمام‌شده: status=expired یا هنوز active ولی تاریخ گذشته (هم‌تعریف با Account::scopeLapsed) */
    private function lapsedServicesBase(CarbonImmutable $now): Builder
    {
        return Account::query()
            ->where('accounts.is_test', false)
            ->where(fn ($q) => $q
                ->where('accounts.status', 'expired')
                ->orWhere(fn ($q2) => $q2->where('accounts.status', 'active')->where('accounts.expires_at', '<=', $now)));
    }

    private function openTickets(): Builder
    {
        return Ticket::query()
            ->whereColumn('tickets.user_id', 'users.id')
            ->where('tickets.status', Ticket::STATUS_OPEN);
    }

    private function googleIdentities(): Builder
    {
        return UserIdentity::query()
            ->whereColumn('user_identities.user_id', 'users.id')
            ->where('user_identities.provider', UserIdentity::PROVIDER_GOOGLE);
    }
}
