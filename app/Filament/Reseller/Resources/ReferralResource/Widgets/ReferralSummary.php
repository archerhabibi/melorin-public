<?php

namespace App\Filament\Reseller\Resources\ReferralResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ReferralResource\Pages\ListReferrals;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use App\Support\Money;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** B5.6 — جمع‌های «همان Query فیلترشده‌ی جدول»؛ هر عدد دقیقاً همان مجموعه‌ای است که جدول نشان می‌دهد. */
class ReferralSummary extends BaseWidget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected function getTablePage(): string
    {
        return ListReferrals::class;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $t = app(ResellerMarketingCenter::class)->referralTotals(static::currentReseller(), $this->getPageTableQuery());
        $rate = $t->conversionPercent();

        return [
            Stat::make('اعضای معرفی‌شده', number_format($t->invited))
                ->description(number_format($t->referrers).' معرف')
                ->color('primary'),
            Stat::make('خرید کرده‌اند', number_format($t->converted))
                ->description($t->invited - $t->converted > 0 ? number_format($t->invited - $t->converted).' نفر هنوز خرید نکرده‌اند' : ($t->invited > 0 ? 'همه خرید کرده‌اند' : 'معرفی‌ای نیست'))
                ->color('success'),
            Stat::make('نرخ تبدیل', $rate === null ? '—' : number_format($rate).'٪')
                ->description('معرفی‌شده ← خریدار')
                ->color($rate === null ? 'gray' : ($rate >= 50 ? 'success' : 'warning')),
            Stat::make('فروش از معرفی‌ها', Money::format($t->revenue))
                ->description('مجموع خرید اعضای معرفی‌شده')
                ->color('info'),
        ];
    }
}
