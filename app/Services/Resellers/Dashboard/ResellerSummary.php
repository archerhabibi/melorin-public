<?php

namespace App\Services\Resellers\Dashboard;

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
     * درصد تغییر نسبت به دوره‌ی قبل (عدد صحیح، گرد به نزدیک‌ترین).
     * بدون مبنا (دوره‌ی قبل صفر) ⇒ null — «۰ → X» درصدِ بی‌معنی است و نباید «∞٪» نشان داده شود.
     */
    public static function percentChange(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        $diff = $current - $previous;
        $base = abs($previous);

        // محاسبه‌ی صحیح با گرد؛ برای مقادیر بسیار بزرگ (سرریز ×۱۰۰) از مقیاس‌کردن مبنا استفاده می‌شود.
        $percent = abs($diff) > intdiv(PHP_INT_MAX, 100)
            ? intdiv(abs($diff), max(1, intdiv($base, 100)))
            : intdiv(abs($diff) * 100 + intdiv($base, 2), $base);

        return $diff < 0 ? -$percent : $percent;
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
