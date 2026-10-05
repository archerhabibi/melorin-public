<?php

namespace App\Services\Resellers\Customers;

use App\Models\Account;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** نمای کامل یک مشتری برای نماینده (B5.2): فقط داده‌ی همین فروشگاه؛ هیچ چیز از Main یا نماینده‌ی دیگر. */
final class CustomerProfile
{
    /**
     * @param  Collection<int, Account>  $services  تازه‌ترین انقضا اول
     * @param  Collection<int, Order>  $recentOrders
     */
    public function __construct(
        public readonly User $user,
        public readonly string $membershipStatus,
        public readonly ?CarbonInterface $joinedAt,
        public readonly int $walletBalance,
        public readonly int $orders,
        public readonly int $renewals,
        public readonly int $revenue,
        public readonly int $profit,
        public readonly ?CarbonInterface $lastOrderAt,
        public readonly Collection $services,
        public readonly Collection $recentOrders,
    ) {}

    public function isActiveMember(): bool
    {
        return $this->membershipStatus === 'active';
    }
}
