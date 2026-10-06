<?php

namespace App\Filament\Widgets\Finance\Concerns;

use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** نمایش KPI با درصد تغییر (هم‌قاعده با StatsOverview داشبورد اجرایی): بدون مبنا ⇒ توضیح خنثی، هرگز «∞٪». */
trait FormatsTrendStats
{
    protected function trendStat(string $label, string $value, ?int $change, DashboardPeriod $period, ?string $note = null): Stat
    {
        $stat = Stat::make($label.' ('.$period->label().')', $value);
        $prefix = $note !== null && $note !== '' ? $note.' · ' : '';

        if ($change === null) {
            return $stat->description($prefix.'بدون مبنای مقایسه');
        }

        $sign = $change > 0 ? '+' : '';

        return $stat
            ->description($prefix.$sign.$change.'٪ '.$period->comparisonLabel())
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->descriptionColor($change >= 0 ? 'success' : 'danger');
    }
}
