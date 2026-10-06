<?php

namespace App\Filament\Reseller\Resources\CommissionResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CommissionResource\Pages\ListCommissions;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B5.4 — خلاصه‌ی بالای فهرست کمیسیون‌ها. عددها جمعِ «همان Query فیلترشده‌ی جدول»اند (بازه، نوع، جست‌وجو و ...)؛
 * پس هر عدد دقیقاً همان چیزی است که جدول زیرش نشان می‌دهد. (در پوشه‌ی Resource است، نه Widgets/ پنل، تا روی داشبورد دیده نشود.)
 */
class CommissionSummary extends BaseWidget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected function getTablePage(): string
    {
        return ListCommissions::class;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $t = app(ResellerCommissionCenter::class)->totals($this->getPageTableQuery());
        $share = $t->shareOfProfit();

        return [
            Stat::make('کمیسیون پرداخت‌شده', Money::format($t->commissionAmount))
                ->description(number_format($t->commissionCount).' پرداخت · '.number_format($t->referrers).' معرف'.($t->pendingCount > 0 ? ' · '.number_format($t->pendingCount).' در انتظار' : ''))
                ->color('primary'),
            Stat::make('پاداش معرفی', Money::format($t->bonusAmount))
                ->description(number_format($t->bonusCount).' پرداخت (جدا از کمیسیون)')
                ->color('info'),
            Stat::make('سهم کمیسیون از سود', $share === null ? '—' : number_format($share).'٪')
                ->description($share === null ? 'سفارشِ کمیسیون‌داری در این فیلتر نیست' : 'نسبت به سود سفارش‌هایی که کمیسیون گرفته‌اند')
                ->color($share === null ? 'gray' : ($share >= 100 ? 'danger' : ($share >= 50 ? 'warning' : 'success'))),
            Stat::make('نیازمند توجه', number_format($t->attentionCount))
                ->description(number_format($t->refundedOrderCount).' روی سفارش بازگشت‌شده ('.Money::format($t->refundedOrderAmount).') · '.number_format($t->exceedsProfitCount).' بیشتر از سود')
                ->color($t->attentionCount > 0 ? 'warning' : 'gray'),
        ];
    }
}
