<?php

namespace App\Filament\Reseller\Widgets;

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

        $monthRevenue = (float) $monthOrders->sum('sold_price');
        $monthProfit = (float) $monthOrders->sum(fn (Order $o) => $o->resellerProfit());
        $customersCount = $reseller->customers()->count();

        return [
            Stat::make('موجودی اعتبار نماینده', number_format(app(WalletService::class)->balance($reseller)).' تومان')
                ->color('success'),
            Stat::make('تعداد مشتریان', $customersCount),
            Stat::make('تعداد خریدها (این ماه)', $monthOrders->count()),
            Stat::make('فروش این ماه', number_format($monthRevenue).' تومان'),
            Stat::make('سود این ماه', number_format($monthProfit).' تومان')
                ->color('success'),
        ];
    }
}
