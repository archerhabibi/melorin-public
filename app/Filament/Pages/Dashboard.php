<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Executive\AttentionAlerts;
use App\Filament\Widgets\Executive\OperationsOverview;
use App\Filament\Widgets\Executive\RecentOrders;
use App\Filament\Widgets\Executive\RevenueTrendChart;
use App\Filament\Widgets\Executive\ServerHealth;
use App\Filament\Widgets\Executive\StatsOverview;
use App\Filament\Widgets\Executive\TopResellers;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * داشبورد اجرایی ادمین (B7.1). فقط یک فیلتر دارد: بازه‌ی زمانی. مقدار فیلتر از URL/Session می‌آید و
 * ورودی کاربر است؛ هر ویجت آن را با DashboardPeriod::fromInput() اعتبارسنجی می‌کند (مقدار نامعتبر ⇒ پیش‌فرض).
 *
 * داشبورد پیش‌فرض Filament و دو ویجت قدیمی (SalesOverviewWidget / SalesChartWidget) جایگزین شدند، نه اضافه.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'داشبورد اجرایی';

    protected static ?string $navigationLabel = 'داشبورد';

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
            OperationsOverview::class,
            RevenueTrendChart::class,
            ServerHealth::class,
            TopResellers::class,
            RecentOrders::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
