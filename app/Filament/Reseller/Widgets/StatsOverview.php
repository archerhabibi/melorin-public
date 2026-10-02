<?php

namespace App\Filament\Reseller\Widgets;

use App\Support\Money;
use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Models\Order;
use App\Services\Core\WalletService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * بند ۱۵ سند نیازمندی Reseller Platform: «Dashboard — آمار ساده ماه
 * جاری: تعداد مشتریان، تعداد خریدها، تعداد تمدیدها، مبلغ فروش، سود».
 */
class StatsOverview extends BaseWidget
{
    use ResolvesCurrentReseller;

    protected function getStats(): array
    {
        $reseller = static::currentReseller();
        $completedStatuses = ['paid', 'account_created'];

        $monthOrders = Order::query()
            ->ofReseller($reseller->id)
            ->whereIn('status', $completedStatuses)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get();

        // این کوئری با ofReseller() محدود شده، یعنی همه‌ی سفارش‌ها در
        // Context نماینده‌اند — پس «مبلغی که مشتری پرداخته» همیشه
        // customers_price است، نه main_price (که اصلاً در این سفارش‌ها
        // پر نمی‌شود).
        $monthRevenue = (int) $monthOrders->sum('customers_price');
        $monthProfit = (int) $monthOrders->sum(fn (Order $o) => $o->resellerProfit());
        $customersCount = $reseller->customers()->count();

        return [
            Stat::make('موجودی اعتبار نماینده', Money::format(app(WalletService::class)->balance($reseller)))
                ->color('success'),
            Stat::make('تعداد مشتریان', $customersCount),
            Stat::make('تعداد خریدها (این ماه)', $monthOrders->count()),
            Stat::make('فروش این ماه', Money::format($monthRevenue)),
            Stat::make('سود این ماه', Money::format($monthProfit))
                ->color('success'),
        ];
    }
}
