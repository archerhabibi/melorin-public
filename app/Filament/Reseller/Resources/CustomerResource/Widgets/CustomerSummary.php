<?php

namespace App\Filament\Reseller\Resources\CustomerResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Services\Resellers\Customers\ResellerCustomerDirectory;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B5.2 — خلاصه‌ی بالای فهرست مشتریان. عددها از همان سرویس و همان تعریف بخش‌هاست که فیلتر جدول استفاده می‌کند.
 * (در پوشه‌ی Resource است، نه Widgets/ پنل، تا روی داشبورد دیده نشود.)
 */
class CustomerSummary extends BaseWidget
{
    use ResolvesCurrentReseller;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = app(ResellerCustomerDirectory::class)->summary(static::currentReseller());

        return [
            Stat::make('مشتریان', number_format($s->total))
                ->description(number_format($s->newInLast30Days).' عضو جدید در ۳۰ روز اخیر'.($s->inactive > 0 ? ' · '.number_format($s->inactive).' غیرفعال/مسدود' : ''))
                ->color('primary'),
            Stat::make('دارای سرویس فعال', number_format($s->withActiveService))
                ->description($s->total > 0 ? number_format((int) round($s->withActiveService * 100 / $s->total)).'٪ از مشتریان' : '—')
                ->color('success'),
            Stat::make('سرویس رو‌به‌انقضا', number_format($s->expiring))
                ->description('تا '.ResellerCustomerDirectory::EXPIRING_DAYS.' روز آینده · '.number_format($s->needsRenewal).' نیازمند تمدید')
                ->color($s->expiring > 0 ? 'warning' : 'gray'),
            Stat::make('موجودی کیف‌پول مشتریان', Money::format($s->totalWalletBalance))
                ->description(number_format($s->neverBought).' مشتری هنوز خرید نکرده')
                ->color('gray'),
        ];
    }
}
