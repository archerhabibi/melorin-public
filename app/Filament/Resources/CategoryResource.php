<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** مدیریت دسته‌بندی‌ها / سبد فروش (بند ۴ سند نیازمندی) */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'فروشگاه';

    protected static ?string $navigationLabel = 'دسته‌بندی‌ها (سبد فروش)';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('نام دسته‌بندی')->required()->maxLength(255),

            Forms\Components\Select::make('server_selection_mode')
                ->label('روش انتخاب سرور')
                ->options([
                    'auto' => 'خودکار (کمترین اکانت فعال)',
                    'manual' => 'دستی توسط کاربر',
                ])
                ->default('auto')
                ->required()
                ->native(false)
                ->helperText('بند ۶ سند: در حالت خودکار، سیستم کم‌بارترین سرور مجاز را انتخاب می‌کند.'),

            Forms\Components\Radio::make('naming_mode')
                ->label('نام‌گذاری اکانت‌های این سبد فروش')
                ->options([
                    'random' => 'خودکار — الگوی «۴حرف‌اول‌سرور_حجم_شماره‌ترتیبی» (مثلاً germ_30_1)',
                    'custom' => 'دلخواه — هنگام خرید از خریدار یک نام لاتین پرسیده شود',
                ])
                ->default('random')
                ->required()
                ->helperText('در حالت «دلخواه»، اگر نامی که کاربر وارد کرده قبلاً استفاده شده باشد، یک عدد ترتیبی به انتهای آن اضافه می‌شود.'),

            Forms\Components\Select::make('serverPanels')
                ->label('سرورهای مجاز این دسته')
                ->relationship('serverPanels', 'name')
                ->multiple()
                ->preload()
                ->searchable(),

            Forms\Components\Toggle::make('status')
                ->label('فعال')
                ->formatStateUsing(fn ($state) => $state === 'active')
                ->dehydrateStateUsing(fn ($state) => $state ? 'active' : 'inactive')
                ->default(true),

            Forms\Components\Toggle::make('available_to_resellers')
                ->label('فعال برای نمایندگان')
                ->default(true)
                ->helperText('در صورت خاموش‌بودن، این سبد فروش و محصولاتش برای همه‌ی نمایندگان (نه یک نماینده‌ی خاص) مخفی و غیرقابل‌فروش می‌شوند.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('server_selection_mode')->label('انتخاب سرور')
                    ->formatStateUsing(fn ($state) => $state === 'auto' ? 'خودکار' : 'دستی'),
                Tables\Columns\BadgeColumn::make('naming_mode')->label('نام‌گذاری')
                    ->formatStateUsing(fn (string $state): string => $state === 'custom' ? 'دلخواه' : 'خودکار')
                    ->colors(['warning' => 'custom', 'gray' => 'random']),
                Tables\Columns\TextColumn::make('serverPanels_count')->counts('serverPanels')->label('تعداد سرور'),
                Tables\Columns\TextColumn::make('products_count')->counts('products')->label('تعداد محصول'),
                Tables\Columns\BadgeColumn::make('status')->label('وضعیت')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
                Tables\Columns\IconColumn::make('available_to_resellers')
                    ->label('برای نمایندگان')
                    ->boolean(),
            ])
            ->actions([
                // طبق درخواست صریح: دکمه‌ی فعال/غیرفعال‌سازی این سبد برای
                // «همه‌ی نمایندگان» یک‌جا — نه برای یک نماینده‌ی خاص.
                // تنظیم قیمت/فعال‌سازیِ تک‌تکِ نمایندگان دست‌نخورده باقی
                // می‌ماند (ResellerProductPrice)؛ این کلید فقط سراسری روی
                // آن‌ها سوار می‌شود.
                Tables\Actions\Action::make('enable_for_resellers')
                    ->label('فعال برای نمایندگان')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Category $record) => ! $record->available_to_resellers)
                    ->requiresConfirmation()
                    ->modalHeading('فعال‌سازی این سبد فروش برای همه‌ی نمایندگان')
                    ->modalDescription('این سبد فروش برای همه‌ی نمایندگان یک‌جا فعال می‌شود، نه یک نماینده‌ی خاص.')
                    ->action(function (Category $record) {
                        $record->update(['available_to_resellers' => true]);

                        Notification::make()
                            ->title('سبد فروش برای همه‌ی نمایندگان فعال شد.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('disable_for_resellers')
                    ->label('غیرفعال برای نمایندگان')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Category $record) => $record->available_to_resellers)
                    ->requiresConfirmation()
                    ->modalHeading('غیرفعال‌سازی این سبد فروش برای همه‌ی نمایندگان')
                    ->modalDescription('این سبد فروش و محصولاتش برای همه‌ی نمایندگان یک‌جا غیرفعال می‌شود، نه یک نماینده‌ی خاص.')
                    ->action(function (Category $record) {
                        $record->update(['available_to_resellers' => false]);

                        Notification::make()
                            ->title('سبد فروش برای همه‌ی نمایندگان غیرفعال شد.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
