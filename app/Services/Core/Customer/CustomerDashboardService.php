<?php

namespace App\Services\Core\Customer;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Collection;

/**
 * B3.1 — خلاصه‌ی داشبورد مشتری (سرویس‌های فعال، کیف‌پول، اعلان‌ها).
 *
 * منطق «کدام سرویس فعال/رو‌به‌انقضا است و چه چیزی باید به مشتری گفته شود» اینجا (Core) است تا
 * Website و Bot از یک منبع بخوانند؛ Controller/View هیچ شرط کسب‌وکاری ندارد.
 *
 * قواعد:
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد (حتی Wallet خالی — برخلاف WalletService::balanceIn).
 *    باز کردن یک صفحه‌ی GET نباید در DB چیزی بنویسد.
 *  - Context-isolated: سرویس‌ها با `customer_account_id` (خودِ آن عضویت، مثل AccountsController)،
 *    سفارش‌ها با customer_account_id + reseller_id، پرداخت‌ها با user_id + reseller_id،
 *    Wallet با scope_key همین Context.
 *  - اعلان‌ها ذخیره نمی‌شوند (بدون وضعیت خوانده/نخوانده)؛ مرکز اعلان ماندگار خارج از B3.1 است.
 */
class CustomerDashboardService
{
    /** «رو‌به‌انقضا»: انقضا در این تعداد روز آینده */
    public const EXPIRING_DAYS = 7;

    /** منقضی‌شده‌ها فقط تا این تعداد روز اعلان می‌شوند (بعد از آن فقط در شمارنده می‌مانند) */
    public const LAPSED_NOTICE_DAYS = 30;

    /** هشدار حجم از این درصد مصرف به بعد */
    public const TRAFFIC_WARN_PERCENT = 90;

    public const FEATURED_LIMIT = 4;

    public const TRANSACTIONS_LIMIT = 5;

    public const NOTICES_LIMIT = 6;

    private const PER_KIND_LIMIT = 3;

    public function __construct(protected WalletCenterService $walletCenter) {}

    public function snapshot(User $user, StoreContext $store, ?CustomerAccount $customer): CustomerDashboard
    {
        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('scope_key', $store->scopeKey())
            ->first();

        $balance = (int) ($wallet?->balance ?? 0);

        $transactions = $wallet
            ? $wallet->transactions()->latest('id')->limit(self::TRANSACTIONS_LIMIT)->get()
            : collect();

        $notices = collect();
        $activeCount = $expiringCount = $lapsedCount = 0;
        $featured = collect();

        if ($customer) {
            $base = fn () => Account::query()->where('customer_account_id', $customer->id);
            $soon = now()->addDays(self::EXPIRING_DAYS);

            $activeCount = $base()->activeNow()->count();
            $expiringCount = $base()->activeNow()->where('expires_at', '<=', $soon)->count();
            $lapsedCount = $base()->lapsed()->count();

            $featured = $base()->activeNow()->with('product')
                ->orderByRaw('expires_at is null')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit(self::FEATURED_LIMIT)
                ->get();

            $notices = $notices
                ->concat($this->lapsedNotices($base()))
                ->concat($this->expiringNotices($base(), $soon))
                ->concat($this->trafficNotices($base()))
                ->concat($this->orderNotices($customer, $store));

            if ($balance <= 0 && ($expiringCount > 0 || $this->hasRecentlyLapsed($base()))) {
                $notices->push(new DashboardNotice(
                    'wallet.empty',
                    DashboardNotice::TONE_INFO,
                    'کیف‌پول شما خالی است',
                    'برای تمدید سرویس، ابتدا کیف‌پول را شارژ کنید.',
                    DashboardNotice::TARGET_CHARGE,
                ));
            }
        }

        $notices = $notices->concat($this->pendingPaymentNotices($user, $store));

        return new CustomerDashboard(
            activeCount: $activeCount,
            expiringSoonCount: $expiringCount,
            lapsedCount: $lapsedCount,
            walletBalance: $balance,
            featuredServices: $featured,
            recentTransactions: $transactions,
            notices: $notices
                ->sortBy(fn (DashboardNotice $n) => $n->severity())
                ->values()
                ->take(self::NOTICES_LIMIT),
        );
    }

    /** @return Collection<int, DashboardNotice> */
    protected function lapsedNotices($query): Collection
    {
        return $query->lapsed()
            ->where('expires_at', '>=', now()->subDays(self::LAPSED_NOTICE_DAYS))
            ->with('product')
            ->orderByDesc('expires_at')
            ->limit(self::PER_KIND_LIMIT)
            ->get()
            ->map(fn (Account $a) => new DashboardNotice(
                'service.expired.'.$a->id,
                DashboardNotice::TONE_DANGER,
                'سرویس منقضی شد',
                $this->label($a).' منقضی شده است؛ برای ادامه تمدید کنید.',
                DashboardNotice::TARGET_ACCOUNT,
                $a->id,
            ));
    }

    /** @return Collection<int, DashboardNotice> */
    protected function expiringNotices($query, $soon): Collection
    {
        return $query->activeNow()
            ->where('expires_at', '<=', $soon)
            ->with('product')
            ->orderBy('expires_at')
            ->limit(self::PER_KIND_LIMIT)
            ->get()
            ->map(function (Account $a) {
                $days = $a->remainingDays();

                return new DashboardNotice(
                    'service.expiring.'.$a->id,
                    DashboardNotice::TONE_WARNING,
                    'سرویس در آستانه‌ی انقضا',
                    $this->label($a).($days === 0 ? ' امروز منقضی می‌شود.' : " تا {$days} روز دیگر منقضی می‌شود."),
                    DashboardNotice::TARGET_ACCOUNT,
                    $a->id,
                );
            });
    }

    /** @return Collection<int, DashboardNotice> */
    protected function trafficNotices($query): Collection
    {
        return $query->activeNow()
            ->where('traffic_gb', '>', 0)
            // ثابت عددی (نه ورودی کاربر) ⇒ مستقیم در SQL؛ Bind شدن به‌صورت رشته در SQLite مقایسه را خراب نکند.
            ->whereRaw(sprintf('COALESCE(traffic_used_gb, 0) >= traffic_gb * %.2F', self::TRAFFIC_WARN_PERCENT / 100))
            ->with('product')
            ->orderByRaw('COALESCE(traffic_used_gb, 0) / traffic_gb desc')
            ->limit(self::PER_KIND_LIMIT)
            ->get()
            ->map(function (Account $a) {
                $exhausted = $a->trafficUsagePercent() === 100;

                return new DashboardNotice(
                    'service.traffic.'.$a->id,
                    $exhausted ? DashboardNotice::TONE_DANGER : DashboardNotice::TONE_WARNING,
                    $exhausted ? 'حجم سرویس تمام شد' : 'حجم سرویس رو به پایان است',
                    $this->label($a).($exhausted
                        ? ' حجم باقی‌مانده ندارد.'
                        : ' بیش از '.self::TRAFFIC_WARN_PERCENT.'٪ حجمش مصرف شده است.'),
                    DashboardNotice::TARGET_ACCOUNT,
                    $a->id,
                );
            });
    }

    /**
     * سفارش «پول گرفته شده، سرویس تحویل نشده». عمداً هیچ دکمه‌ی Retry/Refund ندارد
     * (Admin-only)؛ فقط به مشتری می‌گوید رسیدگی در جریان است.
     *
     * @return Collection<int, DashboardNotice>
     */
    protected function orderNotices(CustomerAccount $customer, StoreContext $store): Collection
    {
        return Order::query()
            ->where('customer_account_id', $customer->id)
            ->where('reseller_id', $store->resellerId())
            ->where('status', Order::STATUS_PROVISION_FAILED)
            ->latest('id')
            ->limit(self::PER_KIND_LIMIT)
            ->get()
            ->map(fn (Order $o) => new DashboardNotice(
                'order.attention.'.$o->id,
                DashboardNotice::TONE_INFO,
                'سفارش شما در حال رسیدگی است',
                "ساخت سرویس سفارش #{$o->id} با مشکل روبه‌رو شد و پشتیبانی آن را پیگیری می‌کند.",
                DashboardNotice::TARGET_ORDER,
                $o->id,
            ));
    }

    /**
     * شارژِ در انتظار. شمارش از `WalletCenterService` (B3.3) می‌آید تا داشبورد و صفحه‌ی کیف‌پول هیچ‌وقت
     * دو عدد متفاوت نشان ندهند؛ شارژ درگاهیِ رهاشده (> ۲۴ ساعت) دیگر «در انتظار تأیید» حساب نمی‌شود.
     *
     * @return Collection<int, DashboardNotice>
     */
    protected function pendingPaymentNotices(User $user, StoreContext $store): Collection
    {
        $count = $this->walletCenter->pendingChargeCount($user, $store);

        if ($count === 0) {
            return collect();
        }

        return collect([new DashboardNotice(
            'payment.pending',
            DashboardNotice::TONE_INFO,
            'شارژ در انتظار تأیید',
            $count === 1
                ? 'یک شارژ کیف‌پول هنوز تأیید نشده است.'
                : "{$count} شارژ کیف‌پول هنوز تأیید نشده است.",
            DashboardNotice::TARGET_WALLET,
        )]);
    }

    protected function hasRecentlyLapsed($query): bool
    {
        return $query->lapsed()
            ->where('expires_at', '>=', now()->subDays(self::LAPSED_NOTICE_DAYS))
            ->exists();
    }

    protected function label(Account $account): string
    {
        return $account->product->name ?? 'سرویس شما';
    }
}
