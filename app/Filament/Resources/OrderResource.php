<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * مشاهده‌ی سفارش‌ها (بخشی از بند ۱۷ سند: گزارشات فروش). عمداً read-only
 * است — سفارش‌ها فقط از طریق AccountService::purchase ساخته می‌شوند.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'فروشگاه';

    protected static ?string $navigationLabel = 'سفارش‌ها';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('user.full_name')->label('کاربر')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->label('محصول'),
                Tables\Columns\TextColumn::make('reseller.user.full_name')->label('نماینده')->placeholder('—'),
                Tables\Columns\TextColumn::make('sales_channel')->label('کانال فروش')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'main_bot' => 'ربات اصلی', 'reseller_bot' => 'ربات نماینده',
                        'website' => 'سایت', 'panel' => 'پنل مدیریت', default => $state,
                    }),
                // این جدول هم سفارش‌های Main و هم سفارش‌های نمایندگی را
                // نشان می‌دهد؛ «مبلغ فروش» بسته به Context، یا
                // main_price است یا customers_price — هیچ‌وقت هردو.
                Tables\Columns\TextColumn::make('customer_paid')
                    ->label('مبلغ فروش')
                    ->getStateUsing(fn (Order $record) => number_format((float) ($record->main_price ?? $record->customers_price)).' تومان'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors([
                        'warning' => 'pending', 'info' => 'paid',
                        'success' => 'account_created', 'danger' => 'failed', 'gray' => 'refunded',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'در انتظار', 'paid' => 'پرداخت‌شده',
                    'account_created' => 'تحویل‌شده', 'failed' => 'ناموفق', 'refunded' => 'بازگشت‌شده',
                ]),
                Tables\Filters\SelectFilter::make('sales_channel')->label('کانال فروش')->options([
                    'main_bot' => 'ربات اصلی', 'reseller_bot' => 'ربات نماینده',
                    'website' => 'سایت', 'panel' => 'پنل مدیریت',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
