<?php

namespace App\Filament\Reseller\Resources\FinanceResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\FinanceResource\Pages\ListFinance;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Finance\ResellerFinanceCenter;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

/**
 * B5.5 — صورت‌حساب بازه. بازه از فیلتر «بازه»ی جدول می‌آید؛ بدون فیلتر، ۳۰ روز اخیر (مثل داشبورد) و همیشه
 * با برچسب بازه نمایش داده می‌شود تا با جمع‌های «همه‌ی زمان‌ها»ی جدول اشتباه نشود.
 */
class StatementPanel extends Widget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected static string $view = 'filament.reseller.widgets.finance-statement';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListFinance::class;
    }

    protected function getViewData(): array
    {
        $period = DashboardPeriod::fromInput($this->tableFilters['period']['value'] ?? null);

        return [
            'statement' => app(ResellerFinanceCenter::class)->statement(static::currentReseller(), $period),
        ];
    }
}
