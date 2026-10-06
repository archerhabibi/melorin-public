<?php

namespace App\Services\Admin\Dashboard;

use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\PercentChange;
use Carbon\CarbonImmutable;

/** KPIهای یک بازه + بازه‌ی قبلِ هم‌طول برای مقایسه (B7.1). درصدها: `PercentChange` (بدون مبنا ⇒ null). */
final class ExecutiveSummary
{
    public function __construct(
        public readonly DashboardPeriod $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ExecutiveTotals $current,
        public readonly ExecutiveTotals $previous,
    ) {}

    public function salesChange(): ?int
    {
        return PercentChange::of($this->current->sales, $this->previous->sales);
    }

    public function platformRevenueChange(): ?int
    {
        return PercentChange::of($this->current->platformRevenue, $this->previous->platformRevenue);
    }

    public function ordersChange(): ?int
    {
        return PercentChange::of($this->current->orders, $this->previous->orders);
    }

    public function newUsersChange(): ?int
    {
        return PercentChange::of($this->current->newUsers, $this->previous->newUsers);
    }

    public function newServicesChange(): ?int
    {
        return PercentChange::of($this->current->newServices, $this->previous->newServices);
    }
}
