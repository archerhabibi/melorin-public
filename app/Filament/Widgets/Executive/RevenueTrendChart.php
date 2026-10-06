<?php

namespace App\Filament\Widgets\Executive;

use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Services\Admin\Dashboard\ExecutiveTrendPoint;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\JalaliDate;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** B7.1 — روند روزانه‌ی فروش و درآمد پلتفرم در بازه‌ی انتخاب‌شده (روزهای بدون فروش صفر هستند). */
class RevenueTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'روند فروش و درآمد پلتفرم';

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
        $points = app(ExecutiveDashboardService::class)->trend($period);

        // فقط نمایش: Minor Unit → واحد اصلی ارز برای محور (محاسبه‌ی مالی نیست).
        $major = fn (int $minor) => $minor / Money::factor();

        return [
            'datasets' => [
                [
                    'label' => 'فروش ('.Money::label().')',
                    'data' => array_map(fn (ExecutiveTrendPoint $p) => $major($p->sales), $points),
                    'fill' => true,
                ],
                [
                    'label' => 'درآمد پلتفرم ('.Money::label().')',
                    'data' => array_map(fn (ExecutiveTrendPoint $p) => $major($p->platformRevenue), $points),
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => array_map(fn (ExecutiveTrendPoint $p) => JalaliDate::format($p->day), $points),
        ];
    }
}
