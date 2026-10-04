<?php

namespace App\Services\Core\Customer;

/**
 * خلاصه‌ی فقط‌خواندنیِ کیف‌پول مشتری در یک StoreContext (B3.3).
 */
final class WalletOverview
{
    public function __construct(
        public readonly int $balance,
        public readonly int $credited,
        public readonly int $debited,
        public readonly int $windowDays,
        public readonly int $pendingCount,
        public readonly int $pendingAmount,
        public readonly bool $hasWallet,
    ) {}
}
