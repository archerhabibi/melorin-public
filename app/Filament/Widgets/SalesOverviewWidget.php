<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * کارت‌های خلاصه‌ی داشبورد (بند ۱۷ سند نیازمندی: «گزارشات و آمار»). قبلاً
 * پنل وب هیچ داشبورد/آماری نداشت (طبق درخواست صریح، مهم‌ترین مورد این
 * دور). سفارش‌های paid/account_created «فروش قطعی‌شده» حساب می‌شوند —
 * pending هنوز پرداخت تأیید نشده و refunded/failed فروش واقعی نیست.
 */
class SalesOverviewWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $completedStatuses = ['paid', 'account_created'];

        // main_price و customers_price هیچ‌وقت هم‌زمان روی یک سفارش پر
        // نیستند (بند ۳۱ سند)، پس SUM هرکدام به‌تنهایی NULLها را نادیده
        // می‌گیرد و جمعشان دقیقاً معادل COALESCE است.
        $todayOrdersQuery = fn () => Order::query()
            ->whereIn('status', $completedStatuses)
            ->whereDate('created_at', today());
        $todayRevenue = (float) $todayOrdersQuery()->sum('main_price')
            + (float) $todayOrdersQuery()->sum('customers_price');

        $monthOrdersQuery = fn () => Order::query()
            ->whereIn('status', $completedStatuses)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $monthRevenue = (float) $monthOrdersQuery()->sum('main_price')
            + (float) $monthOrdersQuery()->sum('customers_price');

        $todayOrdersCount = Order::query()
            ->whereIn('status', $completedStatuses)
            ->whereDate('created_at', today())
            ->count();

        return [
            Stat::make('فروش امروز', number_format($todayRevenue).' تومان')
                ->description($todayOrdersCount.' سفارش')
                ->color('success'),

            Stat::make('فروش این ماه', number_format($monthRevenue).' تومان')
                ->color('success'),

            Stat::make('کاربران فعال', number_format(User::query()->where('status', 'active')->count()))
                ->color('primary'),

            Stat::make('اکانت‌های فعال', number_format(Account::query()->where('status', 'active')->count()))
                ->color('primary'),

            Stat::make('پرداخت‌های در انتظار بررسی', number_format(Payment::query()->where('status', 'pending')->count()))
                ->description('نیاز به تأیید/رد دارند')
                ->color(Payment::query()->where('status', 'pending')->exists() ? 'warning' : 'gray'),

            Stat::make('تیکت‌های باز', number_format(Ticket::query()->where('status', '!=', 'closed')->count()))
                ->color(Ticket::query()->where('status', 'open')->exists() ? 'danger' : 'gray'),
        ];
    }
}
