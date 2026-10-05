<?php

namespace App\Filament\Reseller\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use App\Services\Resellers\Dashboard\TrendPoint;
use App\Support\JalaliDate;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** B5.1 — روند روزانه‌ی فروش و سودِ بازه‌ی انتخاب‌شده (روزهای بدون فروش صفر هستند). */
class RevenueTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesCurrentReseller;

    protected static ?string $heading = 'روند فروش و سود';

    protected static ?int $sort = 30;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $points = app(ResellerDashboardService::class)->trend(static::currentReseller(), $period);

        // فقط نمایش: Minor Unit → واحد اصلی ارز برای محور (محاسبه‌ی مالی نیست).
        $major = fn (int $minor) => $minor / Money::factor();

        return [
            'datasets' => [
                [
                    'label' => 'فروش ('.Money::label().')',
                    'data' => array_map(fn (TrendPoint $p) => $major($p->revenue), $points),
                    'fill' => true,
                ],
                [
                    'label' => 'سود ('.Money::label().')',
                    'data' => array_map(fn (TrendPoint $p) => $major($p->profit), $points),
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => array_map(fn (TrendPoint $p) => JalaliDate::format($p->day), $points),
        ];
    }
}
