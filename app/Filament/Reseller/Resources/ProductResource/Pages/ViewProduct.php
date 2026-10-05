<?php

namespace App\Filament\Reseller\Resources\ProductResource\Pages;

use App\Filament\Reseller\Resources\ProductResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    /** ستون‌های محاسبه‌شده‌ی رکورد (قیمت من/فعال‌بودن) پس از هر اکشن تازه شوند تا دکمه‌ها درست دیده شوند */
    private function refreshRecord(): void
    {
        $this->record = ProductResource::getEloquentQuery()->findOrFail($this->record->getKey());
    }

    /** همان اکشن‌های جدول؛ منطق و پیام‌ها در Resource است تا دو جا دو رفتار نشود */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('set_price')
                ->label('تنظیم قیمت')
                ->icon('heroicon-o-currency-dollar')
                ->form(fn () => ProductResource::priceForm($this->record))
                ->fillForm(fn () => ProductResource::priceFormDefaults($this->record))
                ->action(function (array $data): void {
                    ProductResource::applyPrice($this->record, $data);
                    $this->refreshRecord();
                }),
            Action::make('enable')
                ->label('فعال کردن')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => ProductResource::canEnable($this->record))
                ->action(function (): void {
                    ProductResource::applyEnable($this->record);
                    $this->refreshRecord();
                }),
            Action::make('disable')
                ->label('غیرفعال کردن')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn () => ProductResource::canDisable($this->record))
                ->requiresConfirmation()
                ->action(function (): void {
                    ProductResource::applyDisable($this->record);
                    $this->refreshRecord();
                }),
        ];
    }
}
