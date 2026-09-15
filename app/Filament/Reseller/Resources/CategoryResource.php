<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CategoryResource\Pages;
use App\Models\Category;
use App\Services\Resellers\ResellerPricingService;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * طبق درخواست صریح: «نماینده باید بتواند سبد فروش ربات خودش را فعال و
 * یا غیرفعال کند.»
 *
 * دقیقاً مثل ProductResource این پنل، نماینده اینجا هم مالک داده نیست —
 * سبد فروش را نمی‌سازد، ویرایش نمی‌کند و حذف نمی‌کند؛ فقط یک کلید
 * روشن/خاموش برای ربات خودش دارد. به همین دلیل canCreate/canEdit/
 * canDelete همه false هستند و تنها راه تغییر، اکشن‌های اختصاصی است که
 * ResellerPricingService را صدا می‌زنند.
 *
 * فقط سبدهایی که Core با available_to_resellers اجازه داده اصلاً در این
 * لیست دیده می‌شوند — یعنی نماینده حتی نمی‌داند سبدهای بسته‌شده وجود
 * دارند، همان رفتار تأییدشده در v3.0.5.
 */
class CategoryResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'فروشگاه من';

    protected static ?string $navigationLabel = 'سبدهای فروش';

    protected static ?string $modelLabel = 'سبد فروش';

    protected static ?string $pluralModelLabel = 'سبدهای فروش';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $reseller = static::currentReseller();
        $pricing = app(ResellerPricingService::class);

        return $table
            ->query(
                Category::query()
                    ->where('status', 'active')
                    ->where('available_to_resellers', true)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام سبد فروش')->searchable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('تعداد محصولات فعال')
                    ->getStateUsing(fn (Category $record) => $record->products()->where('status', 'active')->count()),

                Tables\Columns\TextColumn::make('priced_count')
                    ->label('قیمت‌گذاری‌شده توسط من')
                    ->getStateUsing(fn (Category $record) => $record->products()
                        ->where('status', 'active')
                        ->whereHas('resellerPrices', fn ($q) => $q
                            ->where('reseller_id', $reseller->id)
                            ->where('is_enabled', true))
                        ->count()),

                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('فعال در ربات من')
                    ->boolean()
                    ->getStateUsing(fn (Category $record) => $pricing->isCategoryEnabled($reseller, $record)),
            ])
            ->actions([
                Tables\Actions\Action::make('enable')
                    ->label('فعال کردن')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Category $record) => ! $pricing->isCategoryEnabled($reseller, $record))
                    ->action(function (Category $record) use ($reseller, $pricing) {
                        $pricing->setCategoryEnabled($reseller, $record, true);
                        Notification::make()->title('این سبد فروش در ربات شما فعال شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال کردن')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('با غیرفعال کردن، محصولات این سبد در ربات شما نمایش داده نمی‌شوند. قیمت‌هایی که تنظیم کرده‌اید پاک نمی‌شود و با فعال‌سازی دوباره برمی‌گردد.')
                    ->visible(fn (Category $record) => $pricing->isCategoryEnabled($reseller, $record))
                    ->action(function (Category $record) use ($reseller, $pricing) {
                        $pricing->setCategoryEnabled($reseller, $record, false);
                        Notification::make()->title('این سبد فروش در ربات شما غیرفعال شد.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
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
