<?php

namespace App\Filament\Resources\UserResource\Widgets;

use App\Services\Admin\Customers\GlobalCustomerDirectory;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * B7.2 — خلاصه‌ی بالای فهرست سراسری مشتریان. عددها از همان سرویس و همان تعریف بخش‌هاست که فیلتر جدول استفاده می‌کند.
 * (در پوشه‌ی Resource است، نه Widgets/ پنل، تا روی داشبورد اجرایی دیده نشود.)
 */
class GlobalCustomerSummary extends BaseWidget
{
    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $s = app(GlobalCustomerDirectory::class)->summary();
        $percent = fn (int $part): string => $s->total > 0 ? number_format((int) round($part * 100 / $s->total)).'٪ از کاربران' : '—';

        return [
            Stat::make('کاربران', number_format($s->total))
                ->description(number_format($s->newInLast30Days).' عضو جدید در ۳۰ روز اخیر'.($s->inactive > 0 ? ' · '.number_format($s->inactive).' غیرفعال/مسدود' : ''))
                ->color('primary'),
            Stat::make('دارای سرویس فعال', number_format($s->withActiveService))
                ->description($percent($s->withActiveService))
                ->color('success'),
            Stat::make('سرویس رو‌به‌انقضا', number_format($s->expiring))
                ->description('تا '.GlobalCustomerDirectory::EXPIRING_DAYS.' روز آینده · '.number_format($s->needsRenewal).' نیازمند تمدید')
                ->color($s->expiring > 0 ? 'warning' : 'gray'),
            Stat::make('پیش‌پرداخت نزد پلتفرم', Money::format($s->mainWalletBalance))
                ->description(number_format($s->neverBought).' کاربر هنوز خرید نکرده')
                ->color('gray'),
            Stat::make('عضو چند فروشگاه', number_format($s->multiStore))
                ->description($percent($s->multiStore))
                ->color('gray'),
            Stat::make('تیکت باز', number_format($s->withOpenTicket))
                ->description('کاربر با تیکت بازِ بی‌پاسخ')
                ->color($s->withOpenTicket > 0 ? 'warning' : 'gray'),
            Stat::make('هویت یکپارچه', number_format($s->unified))
                ->description('Google + تلگرام · '.number_format($s->googleLinked).' Google · '.number_format($s->telegramLinked).' تلگرام')
                ->color('info'),
            Stat::make('صاحب نمایندگی', number_format($s->resellerOwners))
                ->description('کاربرانی که نمایندگی دارند')
                ->color('gray'),
        ];
    }
}
