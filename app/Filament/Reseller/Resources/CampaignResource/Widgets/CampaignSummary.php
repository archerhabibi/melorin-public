<?php

namespace App\Filament\Reseller\Resources\CampaignResource\Widgets;

use App\Filament\Reseller\Resources\CampaignResource\Pages\ListCampaigns;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** B5.6 — جمع‌های همان Query فیلترشده‌ی جدول کمپین‌ها. */
class CampaignSummary extends BaseWidget
{
    use InteractsWithPageTable;

    protected function getTablePage(): string
    {
        return ListCampaigns::class;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $t = app(ResellerMarketingCenter::class)->campaignTotals($this->getPageTableQuery());
        $rate = $t->deliveryPercent();

        return [
            Stat::make('کمپین‌ها', number_format($t->campaigns))
                ->description($t->active > 0 ? number_format($t->active).' در صف/در حال ارسال' : 'همه به پایان رسیده‌اند')
                ->color('primary'),
            Stat::make('گیرندگان', number_format($t->recipients))->color('gray'),
            Stat::make('ارسال‌شده', number_format($t->sent))
                ->description(number_format($t->failed).' ناموفق')
                ->color($t->failed > 0 ? 'warning' : 'success'),
            Stat::make('نرخ تحویل', $rate === null ? '—' : number_format($rate).'٪')
                ->description('ارسال‌شده / کل گیرنده‌ها')
                ->color($rate === null ? 'gray' : ($rate >= 90 ? 'success' : 'warning')),
        ];
    }
}
