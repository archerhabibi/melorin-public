<?php

namespace App\Filament\Reseller\Resources\ReferralResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ReferralResource\Pages\ListReferrals;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

/** B5.6 — پرمعرفی‌ترین معرف‌ها روی همان Query فیلترشده‌ی جدول (سه کوئری ثابت). */
class TopInviters extends Widget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected static string $view = 'filament.reseller.widgets.top-inviters';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListReferrals::class;
    }

    protected function getViewData(): array
    {
        return [
            'inviters' => app(ResellerMarketingCenter::class)->topInviters(static::currentReseller(), $this->getPageTableQuery()),
        ];
    }
}
