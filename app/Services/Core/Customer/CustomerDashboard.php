<?php

namespace App\Services\Core\Customer;

use App\Models\Account;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;

/**
 * خلاصه‌ی فقط‌خواندنیِ داشبورد مشتری در یک StoreContext (B3.1).
 */
final class CustomerDashboard
{
    /**
     * @param  Collection<int, Account>  $featuredServices  نزدیک‌ترین انقضا اول (با product)
     * @param  Collection<int, WalletTransaction>  $recentTransactions
     * @param  Collection<int, DashboardNotice>  $notices
     */
    public function __construct(
        public readonly int $activeCount,
        public readonly int $expiringSoonCount,
        public readonly int $lapsedCount,
        public readonly int $walletBalance,
        public readonly Collection $featuredServices,
        public readonly Collection $recentTransactions,
        public readonly Collection $notices,
    ) {}

    public function hasServices(): bool
    {
        return $this->activeCount > 0 || $this->lapsedCount > 0;
    }
}
