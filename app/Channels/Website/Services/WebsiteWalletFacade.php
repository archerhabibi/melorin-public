<?php

namespace App\Channels\Website\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Core\Customer\WalletCenterFilter;
use App\Services\Core\Customer\WalletCenterService;
use App\Services\Core\Customer\WalletChargeEntry;
use App\Services\Core\Customer\WalletOverview;
use App\Services\Core\Payments\CheckoutQuote;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Adapter نازک روی WalletService (Checkout) و WalletCenterService (B3.3 — Wallet Center). هیچ منطقی
 * غیر از صدا زدن مستقیم Core و نگاشت «هدف → URL» اینجا نیست.
 */
class WebsiteWalletFacade
{
    public function __construct(
        protected WalletService $wallet,
        protected WalletCenterService $center,
    ) {}

    /** برای Checkout (مسیر قدیمی؛ Wallet را در صورت نبودن می‌سازد) */
    public function balance(User $user, StoreContext $store): int
    {
        return $this->wallet->balanceIn($user, $store);
    }

    /**
     * B4.4 — پیش‌فاکتور (کمبود/موجودی پس از خرید/مبلغ پیشنهادی شارژ) از Core. `$readOnly=true` در DB نمی‌نویسد
     * (صفحه‌ی شارژ)؛ Checkout همان مسیر قدیمی `balance()` را نگه می‌دارد.
     */
    public function quote(User $user, StoreContext $store, int $price, bool $readOnly = false): CheckoutQuote
    {
        return new CheckoutQuote(
            $price,
            $readOnly ? $this->currentBalance($user, $store) : $this->balance($user, $store),
        );
    }

    /** برای صفحه‌ی شارژ: موجودی بدون نوشتن در DB */
    public function currentBalance(User $user, StoreContext $store): int
    {
        return $this->center->balance($user, $store);
    }

    /** @param array<string, mixed> $query معمولاً `$request->query()` */
    public function filter(array $query): WalletCenterFilter
    {
        return WalletCenterFilter::fromInput($query);
    }

    public function overview(User $user, StoreContext $store): WalletOverview
    {
        return $this->center->overview($user, $store);
    }

    /**
     * گردش حساب فیلترشده. فقط مقدارهای پاک‌سازی‌شده‌ی فیلتر به لینک‌های صفحه‌بندی اضافه می‌شود
     * (نه کل Query String کاربر).
     *
     * @return LengthAwarePaginator<WalletTransaction>
     */
    public function transactions(User $user, StoreContext $store, WalletCenterFilter $filter): LengthAwarePaginator
    {
        return $this->center->transactions($user, $store, $filter)->appends($filter->toQuery());
    }

    /** @return Collection<int, WalletChargeEntry> */
    public function charges(User $user, StoreContext $store): Collection
    {
        return $this->center->charges($user, $store);
    }

    public function receiptUrl(WalletChargeEntry $charge, StoreContext $store): ?string
    {
        if (! $charge->canUploadReceipt()) {
            return null;
        }

        return $this->url($store, 'wallet.receipt.show', ['payment' => $charge->paymentId]);
    }

    public function orderUrl(int $orderId, StoreContext $store): string
    {
        return $this->url($store, 'orders.show', ['order' => $orderId]);
    }

    /** @param array<string, mixed> $params */
    protected function url(StoreContext $store, string $name, array $params): string
    {
        return $store->isReseller()
            ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
            : route('website.'.$name, $params);
    }
}
