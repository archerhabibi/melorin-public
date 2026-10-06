<?php

namespace App\Services\Resellers\Dashboard;

use App\Support\PercentChange;
use Carbon\CarbonImmutable;

/**
 * KPIهای یک بازه + بازه‌ی قبلِ هم‌طول برای مقایسه (B5.1).
 */
final class ResellerSummary
{
    public function __construct(
        public readonly DashboardPeriod $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly PeriodTotals $current,
        public readonly PeriodTotals $previous,
    ) {}

    /**
     * درصد تغییر نسبت به دوره‌ی قبل — محاسبه‌ی واحد در `App\Support\PercentChange` (B7.1).
     * بدون مبنا (دوره‌ی قبل صفر) ⇒ null.
     */
    public static function percentChange(int $current, int $previous): ?int
    {
        return PercentChange::of($current, $previous);
    }

    public function revenueChange(): ?int
    {
        return self::percentChange($this->current->revenue, $this->previous->revenue);
    }

    public function profitChange(): ?int
    {
        return self::percentChange($this->current->profit, $this->previous->profit);
    }

    public function ordersChange(): ?int
    {
        return self::percentChange($this->current->orders, $this->previous->orders);
    }

    public function newCustomersChange(): ?int
    {
        return self::percentChange($this->current->newCustomers, $this->previous->newCustomers);
    }
}
