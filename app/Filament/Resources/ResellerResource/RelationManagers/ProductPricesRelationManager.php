<?php

namespace App\Filament\Resources\ResellerResource\RelationManagers;

use App\Models\Product;
use App\Models\Reseller;
use App\Services\Resellers\ResellerPricingService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * تا این نسخه، قیمت‌گذاری محصول برای هر نماینده فقط از خودِ پنل
 * نماینده (که نماینده باید وارد شود) قابل‌انجام بود — هیچ راهی برای
 * ادمین اصلی نبود که مستقیماً این تنظیمات را ببیند یا از قبل برای یک
 * نماینده‌ی تازه تنظیم کند. این RelationManager دقیقاً همان کاری را
 * انجام می‌دهد که App\Filament\Reseller\Resources\ProductResource در
 * پنل خودِ نماینده انجام می‌دهد — از همان ResellerPricingService عبور
 * می‌کند تا هیچ منطق قیمت‌گذاری/اعتبارسنجی دوباره نوشته نشود.
 */
class ProductPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'productPrices';

    protected static ?string $title = 'قیمت‌گذاری محصولات';

    public function table(Table $table): Table
    {
        /** @var Reseller $reseller */
        $reseller = $this->getOwnerRecord();
        $pricingService = app(ResellerPricingService::class);

        return $table
            ->query(Product::query()->where('status', 'active')->with('category'))
            ->columns([
                Tables\Columns\TextColumn::make('category.name')->label('سبد فروش'),
                Tables\Columns\TextColumn::make('name')->label('نام محصول')->searchable(),
                Tables\Columns\TextColumn::make('price')->label('قیمت پایه')->money('IRT', divideBy: 1),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('فعال برای این نماینده')
                    ->boolean()
                    ->getStateUsing(fn (Product $record) => $pricingService->isSellable($reseller, $record)),
                Tables\Columns\TextColumn::make('selling_price')
                    ->label('قیمت فروش نماینده')
                    ->getStateUsing(function (Product $record) use ($reseller) {
                        $price = $record->sellingPriceForReseller($reseller);

                        return $price !== null ? number_format($price).' تومان' : '—';
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('set_price')
                    ->label('تنظیم قیمت')
                    ->icon('heroicon-o-currency-dollar')
                    ->form([
                        Forms\Components\TextInput::make('selling_price')
                            ->label('قیمت فروش (تومان)')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->fillForm(fn (Product $record) => [
                        'selling_price' => $record->sellingPriceForReseller($reseller) ?? $record->price,
                    ])
                    ->action(function (Product $record, array $data) use ($reseller, $pricingService) {
                        try {
                            $pricingService->setSellingPrice($reseller, $record, (float) $data['selling_price']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('قیمت ثبت و محصول برای این نماینده فعال شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال کردن')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Product $record) => $pricingService->isSellable($reseller, $record))
                    ->requiresConfirmation()
                    ->action(function (Product $record) use ($reseller, $pricingService) {
                        $pricingService->disable($reseller, $record);
                        Notification::make()->title('محصول برای این نماینده غیرفعال شد.')->success()->send();
                    }),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canEdit($record): bool
    {
        return false;
    }

    public function canDelete($record): bool
    {
        return false;
    }
}
