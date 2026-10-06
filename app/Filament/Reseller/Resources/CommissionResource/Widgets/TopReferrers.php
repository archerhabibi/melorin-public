<?php

namespace App\Filament\Reseller\Resources\CommissionResource\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CommissionResource\Pages\ListCommissions;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;

/**
 * B5.4 — برترین معرف‌ها بر اساس مجموع پرداختی، روی همان Query فیلترشده‌ی جدول (دو کوئری ثابت).
 */
class TopReferrers extends Widget
{
    use InteractsWithPageTable;
    use ResolvesCurrentReseller;

    protected static string $view = 'filament.reseller.widgets.top-referrers';

    protected int|string|array $columnSpan = 'full';

    protected function getTablePage(): string
    {
        return ListCommissions::class;
    }

    protected function getViewData(): array
    {
        return [
            'referrers' => app(ResellerCommissionCenter::class)->topReferrers($this->getPageTableQuery()),
        ];
    }
}
