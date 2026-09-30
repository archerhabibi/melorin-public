<?php

namespace App\Channels\Website\Services;

use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * فاز W0 بند ۵ Roadmap — Adapter نازک روی WalletService. هیچ منطقی
 * غیر از صدا زدن مستقیم Core اینجا نیست.
 */
class WebsiteWalletFacade
{
    public function __construct(protected WalletService $wallet) {}

    public function balance(User $user, StoreContext $store): float
    {
        return $this->wallet->balanceIn($user, $store);
    }

    /**
     * فاز W4 بند ۱ (پنل کاربری → Wallet): «مشاهده‌ی گردش حساب» — بند
     * ۳۰ زیرسند. این متد هیچ تصمیمی نمی‌گیرد؛ فقط تراکنش‌های همان
     * Wallet‌ای که Core از قبل برای این User+Context ساخته را
     * صفحه‌بندی‌شده برمی‌گرداند (بند ۳۱: Isolation از طریق همان
     * WalletService::walletForContext که قبلاً هم در balance() استفاده
     * شده — نه یک کوئری جدید و مستقل).
     */
    public function transactions(User $user, StoreContext $store, int $perPage = 20): LengthAwarePaginator
    {
        return $this->wallet->walletForContext($user, $store)
            ->transactions()
            ->latest('id')
            ->paginate($perPage);
    }
}
