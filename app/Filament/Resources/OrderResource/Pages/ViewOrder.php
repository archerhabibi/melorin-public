<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retryProvisioning')
                ->label('🔁 تلاش مجدد')
                ->color('warning')
                ->visible(fn () => $this->record->status === Order::STATUS_PROVISION_FAILED)
                ->requiresConfirmation()
                ->modalDescription('سرویس بدون هیچ کسر مالی جدید دوباره ساخته/تمدید می‌شود.')
                ->action(function () {
                    OrderResource::runRetry($this->record);
                    $this->record->refresh();
                }),

            Actions\Action::make('refundOrder')
                ->label('↩️ بازگشت وجه')
                ->color('gray')
                ->visible(fn () => $this->record->status === Order::STATUS_PROVISION_FAILED)
                ->requiresConfirmation()
                ->modalDescription(fn () => OrderResource::refundSummary($this->record))
                ->action(function () {
                    OrderResource::runRefund($this->record);
                    $this->record->refresh();
                }),
        ];
    }
}
