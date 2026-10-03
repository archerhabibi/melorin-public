<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** مدیریت روش‌های پرداخت (بند ۱۲ سند نیازمندی) */
class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?string $navigationLabel = 'روش‌های پرداخت';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('نام روش پرداخت')->required()->maxLength(255),

            Forms\Components\Select::make('type')
                ->label('نوع درگاه')
                ->options([
                    'card_to_card' => 'کارت‌به‌کارت (دستی)',
                    'zarinpal' => 'زرین‌پال (آنلاین)',
                ])
                ->required()
                ->native(false)
                ->live(),

            // تنظیمات اختصاصیِ کارت‌به‌کارت
            Forms\Components\Section::make('اطلاعات کارت')
                ->visible(fn (Forms\Get $get) => $get('type') === 'card_to_card')
                ->schema([
                    Forms\Components\TextInput::make('settings.card_number')->label('شماره کارت'),
                    Forms\Components\TextInput::make('settings.card_holder_name')->label('نام صاحب کارت'),
                    Forms\Components\TextInput::make('settings.bank_name')->label('نام بانک'),
                ]),

            // تنظیمات اختصاصیِ زرین‌پال
            Forms\Components\Section::make('اطلاعات درگاه زرین‌پال')
                ->visible(fn (Forms\Get $get) => $get('type') === 'zarinpal')
                ->schema([
                    Forms\Components\TextInput::make('settings.merchant_id')->label('Merchant ID')->password()->revealable(),
                ]),

            Forms\Components\Toggle::make('status')
                ->label('فعال')
                ->formatStateUsing(fn ($state) => $state === 'active')
                ->dehydrateStateUsing(fn ($state) => $state ? 'active' : 'inactive')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\BadgeColumn::make('type')->label('نوع')
                    ->formatStateUsing(fn ($state) => $state === 'card_to_card' ? 'کارت‌به‌کارت' : 'زرین‌پال'),
                Tables\Columns\BadgeColumn::make('status')->label('وضعیت')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
