<?php

namespace App\Services\Resellers\Dashboard;

/**
 * جمع‌های مالیِ یک بازه برای یک نماینده (B5.1). همه int (Minor Unit)؛ هیچ float مالی نیست.
 */
final class PeriodTotals
{
    public function __construct(
        public readonly int $orders,
        public readonly int $renewals,
        public readonly int $revenue,
        public readonly int $cost,
        public readonly int $profit,
        public readonly int $newCustomers,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }

    /** خریدهای جدید = سفارش‌ها منهای تمدیدها */
    public function purchases(): int
    {
        return $this->orders - $this->renewals;
    }

    /** میانگین مبلغ سفارش (تقسیم صحیح؛ بدون سفارش ⇒ ۰) */
    public function averageOrder(): int
    {
        return $this->orders > 0 ? intdiv($this->revenue, $this->orders) : 0;
    }
}
