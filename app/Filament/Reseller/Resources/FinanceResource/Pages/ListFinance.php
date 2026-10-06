<?php

namespace App\Filament\Reseller\Resources\FinanceResource\Pages;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\FinanceResource;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\CreditSummary;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\LedgerSummary;
use App\Filament\Reseller\Resources\FinanceResource\Widgets\StatementPanel;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListFinance extends ListRecords
{
    use ResolvesCurrentReseller;

    protected static string $resource = FinanceResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'مرکز مالی';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'اعتبار شما همان کیف‌پول صاحب فروشگاه در Melorin است و هزینه‌ی تأمین هر فروش از آن کسر می‌شود. این صفحه فقط مشاهده است.';
    }

    protected function getHeaderWidgets(): array
    {
        return [CreditSummary::class, StatementPanel::class, LedgerSummary::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
