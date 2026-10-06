<?php

namespace App\Services\Admin\Finance;

use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\PercentChange;
use Carbon\CarbonImmutable;

/** جمع‌های مالی یک بازه + بازه‌ی قبلِ هم‌طول (B7.3). درصدها: `PercentChange` (بدون مبنا ⇒ null). */
final class FinanceSummary
{
    public function __construct(
        public readonly DashboardPeriod $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly FinanceTotals $current,
        public readonly FinanceTotals $previous,
    ) {}

    public function salesChange(): ?int
    {
        return PercentChange::of($this->current->sales, $this->previous->sales);
    }

    public function platformRevenueChange(): ?int
    {
        return PercentChange::of($this->current->platformRevenue, $this->previous->platformRevenue);
    }

    public function platformNetChange(): ?int
    {
        return PercentChange::of($this->current->platformNet(), $this->previous->platformNet());
    }

    public function referralCostChange(): ?int
    {
        return PercentChange::of($this->current->referralCost(), $this->previous->referralCost());
    }

    public function cashInChange(): ?int
    {
        return PercentChange::of($this->current->cashIn, $this->previous->cashIn);
    }

    public function netCashInChange(): ?int
    {
        return PercentChange::of($this->current->netCashIn(), $this->previous->netCashIn());
    }
}
