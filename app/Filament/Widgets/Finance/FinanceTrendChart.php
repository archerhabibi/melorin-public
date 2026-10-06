<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Admin\Finance\FinanceTrendPoint;
use App\Services\Admin\Finance\GlobalFinanceService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\JalaliDate;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** B7.3 — روند روزانه‌ی درآمد پلتفرم، ورودی نقد و بازگشت‌ها در بازه (روزهای خالی صفر هستند). */
class FinanceTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'روند درآمد، ورودی نقد و بازگشت‌ها';

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);
        $points = app(GlobalFinanceService::class)->trend($period);

        // فقط نمایش: Minor Unit → واحد اصلی ارز برای محور (محاسبه‌ی مالی نیست).
        $major = fn (int $minor) => $minor / Money::factor();

        return [
            'datasets' => [
                [
                    'label' => 'درآمد پلتفرم ('.Money::label().')',
                    'data' => array_map(fn (FinanceTrendPoint $p) => $major($p->platformRevenue), $points),
                    'fill' => true,
                ],
                [
                    'label' => 'ورودی نقد ('.Money::label().')',
                    'data' => array_map(fn (FinanceTrendPoint $p) => $major($p->cashIn), $points),
                    'borderDash' => [6, 4],
                ],
                [
                    'label' => 'بازگشت‌ها ('.Money::label().')',
                    'data' => array_map(fn (FinanceTrendPoint $p) => $major($p->refunds), $points),
                    'borderColor' => '#e11d48',
                    'backgroundColor' => '#e11d48',
                ],
            ],
            'labels' => array_map(fn (FinanceTrendPoint $p) => JalaliDate::format($p->day), $points),
        ];
    }
}
