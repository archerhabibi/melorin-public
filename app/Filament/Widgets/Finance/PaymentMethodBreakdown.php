<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Admin\Finance\GlobalFinanceService;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** B7.3 — دریافت‌ها به‌تفکیک روش پرداخت در بازه (شارژ تأییدشده و بازگشت پرداخت). */
class PaymentMethodBreakdown extends Widget
{
    use InteractsWithPageFilters;

    /**
     * Avoid dispatching Filament's internal __lazyLoad hook to the parent
     * page when running older Livewire/Filament combinations.
     */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.finance.payment-methods';

    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = 1;

    /** برچسب نمایشی نوع روش (فقط UI) */
    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            'card_to_card' => 'کارت به کارت',
            'rial_gateway' => 'درگاه ریالی',
            'crypto' => 'رمزارز',
            'other' => 'سایر',
            default => '—',
        };
    }

    protected function getViewData(): array
    {
        $period = DashboardPeriod::fromInput($this->filters['period'] ?? null);

        return [
            'period' => $period,
            'rows' => app(GlobalFinanceService::class)->paymentMethods($period),
        ];
    }
}
