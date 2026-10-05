<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Widgets\AttentionAlerts;
use App\Filament\Reseller\Widgets\RecentOrders;
use App\Filament\Reseller\Widgets\RevenueTrendChart;
use App\Filament\Reseller\Widgets\StatsOverview;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * داشبورد نماینده (B5.1). فقط یک فیلتر دارد: بازه‌ی زمانی. مقدار فیلتر از URL/Session می‌آید و
 * ورودی کاربر است؛ هر ویجت آن را با DashboardPeriod::fromInput() اعتبارسنجی می‌کند (مقدار نامعتبر ⇒ پیش‌فرض).
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'داشبورد';

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Select::make('period')
                ->label('بازه‌ی زمانی')
                ->options(DashboardPeriod::labels())
                ->default(DashboardPeriod::DEFAULT->value)
                ->native(false)
                ->selectablePlaceholder(false),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            AttentionAlerts::class,
            StatsOverview::class,
            RevenueTrendChart::class,
            RecentOrders::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }
}
