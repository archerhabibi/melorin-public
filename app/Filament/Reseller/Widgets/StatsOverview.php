<?php

namespace App\Filament\Reseller\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B5.1 — KPIهای نماینده: درآمد، سود، سفارش‌ها، میانگین سفارش، مشتریان، اعتبار.
 *
 * بند ۱۵ سند Reseller Platform («آمار ساده‌ی ماه جاری») حالا بازه‌ی قابل‌انتخاب + مقایسه با دوره‌ی قبل دارد.
 * همه‌ی منطق در ResellerDashboardService است؛ این‌جا فقط نمایش.
 */
class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;
    use ResolvesCurrentReseller;

    protected static ?int $sort = 20;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $reseller = static::currentReseller();
        $service = app(ResellerDashboardService::class);

        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $summary = $service->summary($reseller, $period);
        $position = $service->position($reseller);
        $current = $summary->current;

        $creditColor = $position->isOutOfCredit() ? 'danger' : ($position->isInDebt() ? 'warning' : 'success');
        $creditHint = $position->isInDebt()
            ? 'بدهی — قدرت خرید: '.Money::format(max(0, $position->purchasingPower()))
            : ($position->debtLimit > 0 ? 'سقف بدهی مجاز: '.Money::format($position->debtLimit) : 'بدون سقف بدهی');

        return [
            $this->trendStat('فروش', Money::format($current->revenue), $summary->revenueChange(), $period)->color('success'),
            $this->trendStat('سود', Money::format($current->profit), $summary->profitChange(), $period)->color('success'),
            $this->trendStat('سفارش‌ها', number_format($current->orders), $summary->ordersChange(), $period)
                ->description($current->purchases().' خرید · '.$current->renewals.' تمدید'
                    .($position->inProgressOrders > 0 ? ' · '.$position->inProgressOrders.' در حال تحویل' : '')),
            Stat::make('میانگین هر سفارش', Money::format($current->averageOrder()))
                ->description($period->label()),
            Stat::make('مشتریان', number_format($position->totalCustomers))
                ->description(number_format($current->newCustomers).' مشتری جدید ('.$period->label().')')
                ->color('primary'),
            Stat::make('اعتبار نماینده', Money::format($position->balance))
                ->description($creditHint)
                ->color($creditColor),
        ];
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
