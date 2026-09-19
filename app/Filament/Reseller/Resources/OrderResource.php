<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'مشتریان و فروش';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'سفارش‌ها';

    protected static ?string $modelLabel = 'سفارش';

    protected static ?string $pluralModelLabel = 'سفارش‌ها';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('user.full_name')->label('مشتری'),
                Tables\Columns\TextColumn::make('product.name')->label('محصول'),
                Tables\Columns\TextColumn::make('reseller_price')->label('هزینه‌ی تأمین (reseller_price)')->money('IRT', divideBy: 1),
                Tables\Columns\TextColumn::make('customers_price')->label('قیمت فروش (customers_price)')->money('IRT', divideBy: 1),
                Tables\Columns\TextColumn::make('profit')
                    ->label('سود')
                    ->getStateUsing(fn (Order $record) => number_format($record->resellerProfit()).' تومان'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => Order::statusLabels()[$state] ?? $state)
                    ->colors([
                        'warning' => ['pending', 'provisioning'], 'success' => ['paid', 'account_created'],
                        'danger' => ['failed', 'provision_failed'], 'gray' => 'refunded',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'در انتظار', 'paid' => 'پرداخت‌شده', 'account_created' => 'تحویل‌شده',
                    'failed' => 'ناموفق', 'refunded' => 'بازگشت‌شده',
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->ofReseller(static::currentReseller()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
