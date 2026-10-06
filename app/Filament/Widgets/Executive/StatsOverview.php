<?php

namespace App\Filament\Widgets\Executive;

use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B7.1 — KPIهای بازه‌ی انتخابی با مقایسه‌ی هم‌طول: فروش، درآمد پلتفرم، سفارش‌ها، کاربران و سرویس‌های جدید، نرخ شکست.
 * همه‌ی منطق در ExecutiveDashboardService است؛ این‌جا فقط نمایش.
 */
class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 20;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $summary = app(ExecutiveDashboardService::class)->summary($period);
        $current = $summary->current;

        $rate = $current->failureRatePerMille();
        $rateColor = $current->failedOrders === 0 ? 'success' : ($rate >= 50 ? 'danger' : 'warning');

        return [
            $this->trendStat('فروش', Money::format($current->sales), $summary->salesChange(), $period)
                ->description($this->salesSplit($summary->current->directSales, $current->resellerSales, $current->resellerSharePercent()))
                ->color('success'),
            $this->trendStat('درآمد پلتفرم', Money::format($current->platformRevenue), $summary->platformRevenueChange(), $period)
                ->color('success'),
            $this->trendStat('سفارش‌ها', number_format($current->orders), $summary->ordersChange(), $period)
                ->description($current->purchases().' خرید · '.$current->renewals.' تمدید · میانگین '.Money::format($current->averageOrder())),
            $this->trendStat('کاربران جدید', number_format($current->newUsers), $summary->newUsersChange(), $period)
                ->color('primary'),
            $this->trendStat('سرویس‌های جدید', number_format($current->newServices), $summary->newServicesChange(), $period)
                ->color('primary'),
            Stat::make('نرخ شکست سفارش ('.$period->label().')', sprintf('%d.%d٪', intdiv($rate, 10), $rate % 10))
                ->description(number_format($current->failedOrders).' سفارش ناموفق')
                ->color($rateColor),
        ];
    }

    private function salesSplit(int $direct, int $reseller, int $resellerPercent): string
    {
        return 'مستقیم '.Money::format($direct).' · نمایندگی '.Money::format($reseller).' ('.$resellerPercent.'٪)';
    }

    /** KPI با فلش و درصد تغییر؛ بدون مبنای مقایسه ⇒ توضیح خنثی (نه «∞٪») */
    private function trendStat(string $label, string $value, ?int $change, DashboardPeriod $period): Stat
    {
        $stat = Stat::make($label.' ('.$period->label().')', $value);

        if ($change === null) {
            return $stat->description('بدون مبنای مقایسه');
        }

        $sign = $change > 0 ? '+' : '';

        return $stat
            ->description($sign.$change.'٪ '.$period->comparisonLabel())
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->descriptionColor($change >= 0 ? 'success' : 'danger');
    }
}
