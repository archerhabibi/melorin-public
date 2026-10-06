<?php

namespace App\Filament\Widgets\Executive;

use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** B7.1 — برترین نمایندگانِ بازه‌ی انتخابی بر پایه‌ی درآمدِ پلتفرم از آن‌ها. */
class TopResellers extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.executive.top-resellers';

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);

        return [
            'period' => $period,
            'rows' => app(ExecutiveDashboardService::class)->topResellers($period),
        ];
    }
}
