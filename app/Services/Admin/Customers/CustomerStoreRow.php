<?php

namespace App\Services\Admin\Customers;

use Carbon\CarbonInterface;

/**
 * حضور یک کاربر در یک فروشگاه (B7.2). `scopeKey`: `main` یا `reseller:{id}`.
 * `membershipStatus`: active|disabled|blocked، `removed` (عضویت حذف‌نرم‌شده) یا null (عضویتی نیست ولی سفارش/موجودی هست).
 */
final class CustomerStoreRow
{
    public function __construct(
        public readonly string $scopeKey,
        public readonly ?int $resellerId,
        public readonly ?string $resellerSlug,
        public readonly ?string $membershipStatus,
        public readonly ?CarbonInterface $joinedAt,
        public readonly int $walletBalance,
        public readonly int $orders,
        public readonly int $spent,
        public readonly int $platformRevenue,
        public readonly int $activeServices,
        public readonly ?CarbonInterface $lastOrderAt,
    ) {}

    public function isMain(): bool
    {
        return $this->scopeKey === 'main';
    }
}
