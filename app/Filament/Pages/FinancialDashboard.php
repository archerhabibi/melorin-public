<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Finance\BalanceSheet;
use App\Filament\Widgets\Finance\CashFlow;
use App\Filament\Widgets\Finance\FinanceHealth;
use App\Filament\Widgets\Finance\FinanceTrendChart;
use App\Filament\Widgets\Finance\PaymentMethodBreakdown;
use App\Filament\Widgets\Finance\ProfitAndLoss;
use App\Filament\Widgets\Finance\ResellerReceivables;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * داشبورد مالی سراسری ادمین (B7.3) در `/admin/finance`. هم‌الگوی داشبورد اجرایی (B7.1): فقط یک فیلتر «بازه‌ی زمانی»
 * از URL/Session که هر ویجت با DashboardPeriod::fromInput() اعتبارسنجی می‌کند (مقدار نامعتبر ⇒ پیش‌فرض).
 *
 * فقط‌مشاهده است؛ هیچ تسویه، اصلاح موجودی یا بازگشت وجهی از این صفحه انجام نمی‌شود.
 */
class FinancialDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/finance';

    protected static ?string $slug = 'finance';

    protected static ?string $title = 'داشبورد مالی';

    protected static ?string $navigationLabel = 'داشبورد مالی';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 0;

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
            FinanceHealth::class,
            ProfitAndLoss::class,
            CashFlow::class,
            BalanceSheet::class,
            FinanceTrendChart::class,
            PaymentMethodBreakdown::class,
            ResellerReceivables::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
