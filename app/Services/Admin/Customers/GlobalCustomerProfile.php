<?php

namespace App\Services\Admin\Customers;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * پرونده‌ی سراسری یک مشتری برای ادمین پلتفرم (B7.2): هویت + همه‌ی فروشگاه‌ها، جدا از هم (R8: Merge نمی‌شوند؛
 * جمع‌ها فقط برای مشاهده‌اند و به‌معنای قابل‌خرج‌بودن موجودی در فروشگاه دیگر نیستند).
 */
final class GlobalCustomerProfile
{
    /**
     * @param  Collection<int, CustomerStoreRow>  $stores  فروشگاه اصلی اول؛ بعد بیشترین خرید
     * @param  Collection<int, Account>  $services
     * @param  array<int, string>  $serviceStoreKeys  شناسه‌ی Account ← scope_key فروشگاه
     * @param  Collection<int, Order>  $recentOrders
     * @param  Collection<int, Payment>  $recentPayments
     * @param  Collection<int, Ticket>  $recentTickets
     * @param  Collection<int, WalletTransaction>  $recentWalletTransactions  هر ردیف `wallet` (با scope_key) دارد؛ به‌تفکیک فروشگاه
     */
    public function __construct(
        public readonly User $user,
        public readonly ?UserIdentity $google,
        public readonly ?User $referrer,
        public readonly int $referredCount,
        public readonly ?Reseller $ownedReseller,
        public readonly Collection $stores,
        public readonly int $orders,
        public readonly int $renewals,
        public readonly int $spent,
        public readonly int $platformRevenue,
        public readonly int $activeServices,
        public readonly int $lapsedServices,
        public readonly ?CarbonInterface $lastOrderAt,
        public readonly int $openTickets,
        public readonly Collection $services,
        public readonly array $serviceStoreKeys,
        public readonly Collection $recentOrders,
        public readonly Collection $recentPayments,
        public readonly Collection $recentTickets,
        public readonly Collection $recentWalletTransactions,
    ) {}

    /** مجموع موجودی همه‌ی کیف‌پول‌ها (فقط نمایشی؛ هر کیف‌پول فقط در فروشگاه خودش خرج می‌شود) */
    public function totalWalletBalance(): int
    {
        return (int) $this->stores->sum(fn (CustomerStoreRow $s) => $s->walletBalance);
    }

    public function hasUnifiedIdentity(): bool
    {
        return $this->google !== null && $this->user->telegram_id !== null;
    }
}
