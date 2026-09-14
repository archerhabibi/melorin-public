<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Services\Resellers\ResellerPricingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * طبق بند ۵ سند نیازمندی Reseller Platform: نماینده Catalog مستقل
 * ندارد، فقط می‌تواند روی محصولِ مجاز فعال/غیرفعال و قیمت فروش تعیین
 * کند — نه محصول جدید بسازد، نه محصول موجود را حذف/ویرایش کند. به
 * همین دلیل canCreate/canEdit/canDelete همه false هستند و تنها راه
 * تغییر، اکشن‌های اختصاصی «تنظیم قیمت»/«غیرفعال کردن» است که مستقیماً
 * ResellerPricingService را صدا می‌زنند (نه فرم پیش‌فرض Filament که
 * مستقیم به ستون‌های Product می‌نویسد).
 */
class ProductResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'محصولات';

    protected static ?string $modelLabel = 'محصول';

    protected static ?string $pluralModelLabel = 'محصولات';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $reseller = static::currentReseller();
        $pricingService = app(ResellerPricingService::class);

        return $table
            ->query(Product::query()->where('status', 'active')->with('category'))
            ->columns([
                Tables\Columns\TextColumn::make('category.name')->label('سبد فروش'),
                Tables\Columns\TextColumn::make('name')->label('نام محصول')->searchable(),
                Tables\Columns\TextColumn::make('price')->label('قیمت پایه')->money('IRT', divideBy: 1),
                Tables\Columns\TextColumn::make('duration_days')->label('مدت (روز)'),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('فعال برای من')
                    ->boolean()
                    ->getStateUsing(fn (Product $record) => $pricingService->isSellable($reseller, $record)),
                Tables\Columns\TextColumn::make('selling_price')
                    ->label('قیمت فروش من')
                    ->getStateUsing(function (Product $record) use ($reseller) {
                        $price = $record->sellingPriceForReseller($reseller);

                        return $price !== null ? number_format($price).' تومان' : '—';
                    }),
            ])
            // طبق درخواست صریح: محصولات هر سبد فروش زیرمجموعه‌ی همان
            // سبد نمایش داده شوند تا بررسیِ نماینده هم راحت‌تر باشد.
            ->defaultGroup(
                Tables\Grouping\Group::make('category.name')
                    ->label('سبد فروش')
                    ->collapsible()
            )
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('سبد فروش')
                    ->relationship('category', 'name'),
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

                        Notification::make()->title('قیمت ثبت و محصول فعال شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال کردن')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Product $record) => $pricingService->isSellable($reseller, $record))
                    ->requiresConfirmation()
                    ->action(function (Product $record) use ($reseller, $pricingService) {
                        $pricingService->disable($reseller, $record);
                        Notification::make()->title('محصول برای فروشگاه شما غیرفعال شد.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
