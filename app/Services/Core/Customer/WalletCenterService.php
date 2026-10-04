<?php

namespace App\Services\Core\Customer;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * B3.3 — Wallet Center: خلاصه، گردش حساب فیلتر‌پذیر و شارژهای اخیر مشتری.
 *
 * منطق «کدام تراکنش/شارژ مال این مشتری در این Context است» و «شارژ در چه وضعیتی منتظر چیست» اینجا
 * (Core) است تا Website و Bot (T3) از یک منبع بخوانند؛ Controller/View هیچ شرط کسب‌وکاری ندارد.
 *
 * قواعد:
 *  - فقط‌خواندنی: هیچ رکوردی نمی‌سازد (حتی Wallet خالی — برخلاف `WalletService::balanceIn`).
 *    باز کردن صفحه‌ی GET نباید در DB چیزی بنویسد (هم‌الگو با D4.1). تغییر موجودی همچنان فقط با `WalletService`.
 *  - Context-isolated: Wallet با `user_id + scope_key` همین Context؛ پرداخت با `user_id + reseller_id +
 *    wallet_owner_type=user` (همان کلید مالکیت `Payment::findPendingForReceipt`).
 *  - مرز اعتماد: لینک سفارش فقط وقتی ساخته می‌شود که سفارش واقعاً متعلق به همین کاربر در همین Context باشد
 *    (تراکنش «هزینه‌ی تأمین» Wallet نماینده به سفارش مشتریِ او اشاره می‌کند — نباید لینک مرده/نشت بدهد).
 */
class WalletCenterService
{
    public const PER_PAGE = 15;

    /** بازه‌ی کارت‌های «دریافتی/پرداختی» */
    public const SUMMARY_DAYS = 30;

    public const CHARGES_LIMIT = 10;

    /** شارژ درگاهیِ pending که از این مدت بگذرد «ناتمام» حساب می‌شود (کاربر درگاه را رها کرده) */
    public const GATEWAY_STALE_HOURS = 24;

    /** موجودی بدون ساخت Wallet (Wallet ندارد ⇒ ۰) */
    public function balance(User $user, StoreContext $store): int
    {
        return (int) ($this->wallet($user, $store)?->balance ?? 0);
    }

    public function overview(User $user, StoreContext $store): WalletOverview
    {
        $wallet = $this->wallet($user, $store);

        $credited = $debited = 0;

        if ($wallet) {
            $sums = $wallet->transactions()
                ->where('created_at', '>=', now()->subDays(self::SUMMARY_DAYS))
                ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as credited')
                ->selectRaw('COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) as debited')
                ->first();

            $credited = (int) ($sums->credited ?? 0);
            $debited = (int) ($sums->debited ?? 0);
        }

        $pending = $this->pendingQuery($user, $store);

        return new WalletOverview(
            balance: (int) ($wallet?->balance ?? 0),
            credited: $credited,
            debited: $debited,
            windowDays: self::SUMMARY_DAYS,
            pendingCount: (clone $pending)->count(),
            pendingAmount: (int) (clone $pending)->sum('amount'),
            hasWallet: $wallet !== null,
        );
    }

    /**
     * گردش حساب صفحه‌بندی‌شده (جدیدترین اول). هر تراکنش یک attribute غیرذخیره‌ای `linked_order_id` دارد
     * (null اگر سفارشی نیست یا مال این کاربر در این Context نیست).
     *
     * @return LengthAwarePaginator<WalletTransaction>
     */
    public function transactions(User $user, StoreContext $store, ?WalletCenterFilter $filter = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $filter ??= new WalletCenterFilter;
        $wallet = $this->wallet($user, $store);

        if (! $wallet) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $page = $wallet->transactions()
            ->when($filter->direction === WalletCenterFilter::DIRECTION_IN, fn ($q) => $q->where('amount', '>', 0))
            ->when($filter->direction === WalletCenterFilter::DIRECTION_OUT, fn ($q) => $q->where('amount', '<', 0))
            ->when($filter->type !== null, fn ($q) => $q->where('type', $filter->type))
            ->when($filter->from !== null, fn ($q) => $q->where('created_at', '>=', $filter->from))
            ->when($filter->to !== null, fn ($q) => $q->where('created_at', '<=', $filter->to))
            ->latest('id')
            ->paginate($perPage);

        $this->annotateOrderLinks($page, $user, $store);

        return $page;
    }

    /**
     * شارژهای اخیر (همه‌ی وضعیت‌ها)، جدیدترین اول.
     *
     * @return Collection<int, WalletChargeEntry>
     */
    public function charges(User $user, StoreContext $store, int $limit = self::CHARGES_LIMIT): Collection
    {
        return $this->chargesQuery($user, $store)
            ->with('paymentMethod')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Payment $p) => WalletChargeEntry::fromPayment($p, self::GATEWAY_STALE_HOURS));
    }

    /** تعداد شارژهای منتظر (مرجع واحد برای صفحه‌ی کیف‌پول و اعلان داشبورد) */
    public function pendingChargeCount(User $user, StoreContext $store): int
    {
        return $this->pendingQuery($user, $store)->count();
    }

    protected function wallet(User $user, StoreContext $store): ?Wallet
    {
        return Wallet::query()
            ->where('user_id', $user->id)
            ->where('scope_key', $store->scopeKey())
            ->first();
    }

    /** پرداخت‌های `wallet_charge` شخصیِ این کاربر در این Context */
    protected function chargesQuery(User $user, StoreContext $store): Builder
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->where('reseller_id', $store->resellerId())
            ->where('wallet_owner_type', 'user')
            ->where('purpose', 'wallet_charge');
    }

    /**
     * pending واقعی: کارت‌به‌کارت همیشه (منتظر رسید/ادمین)، درگاهی فقط تا GATEWAY_STALE_HOURS
     * (بعد از آن کاربر درگاه را رها کرده و «در انتظار تأیید» دروغ است).
     */
    protected function pendingQuery(User $user, StoreContext $store): Builder
    {
        return $this->chargesQuery($user, $store)
            ->where('status', 'pending')
            ->where(function (Builder $q) {
                $q->where('created_at', '>=', now()->subHours(self::GATEWAY_STALE_HOURS))
                    ->orWhereHas('paymentMethod', fn ($m) => $m->where('type', 'card_to_card'));
            });
    }

    /** @param LengthAwarePaginator<WalletTransaction> $page */
    protected function annotateOrderLinks(LengthAwarePaginator $page, User $user, StoreContext $store): void
    {
        $orderType = (new Order)->getMorphClass();

        $candidateIds = $page->getCollection()
            ->where('reference_type', $orderType)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $owned = $candidateIds->isEmpty() ? [] : Order::query()
            ->whereIn('id', $candidateIds)
            ->where('reseller_id', $store->resellerId())
            ->whereHas('customerAccount', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($page->getCollection() as $tx) {
            $tx->setAttribute(
                'linked_order_id',
                $tx->reference_type === $orderType && in_array((int) $tx->reference_id, $owned, true) ? (int) $tx->reference_id : null,
            );
        }
    }
}
