<?php

namespace App\Filament\Reseller\Resources\FinanceResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\FinanceResource\Pages\ListFinance;
use App\Services\Resellers\Finance\ResellerFinanceCenter;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** B5.5 — جمع‌های «همان Query فیلترشده‌ی جدول»؛ هر عدد دقیقاً همان مجموعه‌ای است که جدول نشان می‌دهد. */
class LedgerSummary extends BaseWidget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected function getTablePage(): string
    {
        return ListFinance::class;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $t = app(ResellerFinanceCenter::class)->ledgerTotals($this->getPageTableQuery());
        $net = $t->netChange();

        return [
            Stat::make('تغییر خالص اعتبار', ($net > 0 ? '+' : '').Money::format($net))
                ->description(number_format($t->count).' حرکت · +'.Money::format($t->credited).' / −'.Money::format($t->debited))
                ->color($net >= 0 ? 'success' : 'danger'),
            Stat::make('هزینه‌ی تأمین (خالص)', Money::format($t->netSupplyCost()))
                ->description('کسر '.Money::format($t->supplySpent).' · بازگشت '.Money::format($t->supplyRefunded))
                ->color('primary'),
            Stat::make('شارژ اعتبار', Money::format($t->charged))
                ->description('به Melorin')
                ->color('info'),
            Stat::make('اصلاحات مدیر (خالص)', ($t->adjustedNet > 0 ? '+' : '').Money::format($t->adjustedNet))
                ->description('افزایش/کاهش دستی توسط مدیریت')
                ->color($t->adjustedNet === 0 ? 'gray' : 'warning'),
        ];
    }
}
