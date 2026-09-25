<?php

namespace App\Channels\Website\Services;

use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;

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
}
