<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Admin\Finance\GlobalFinanceService;
use Filament\Widgets\Widget;

/** B7.3 — بدهکارترین نمایندگان (مطالبات پلتفرم؛ مستقل از بازه). */
class ResellerReceivables extends Widget
{
    /**
     * Avoid dispatching Filament's internal __lazyLoad hook to the parent
     * page when running older Livewire/Filament combinations.
     */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.finance.reseller-receivables';

    protected static ?int $sort = 70;

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        return ['rows' => app(GlobalFinanceService::class)->debtors()];
    }
}
