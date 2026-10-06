<?php

namespace App\Filament\Widgets\Executive;

use App\Models\Order;
use App\Services\Admin\Dashboard\ExecutiveDashboardService;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** B7.1 — آخرین سفارش‌های پلتفرم (همه‌ی فروشگاه‌ها و وضعیت‌ها، بدون اکانت تست؛ از همان Scope سرویس Core). */
class RecentOrders extends BaseWidget
{
    protected static ?string $heading = 'آخرین سفارش‌ها';

    protected static ?int $sort = 60;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ExecutiveDashboardService::class)->recentOrdersQuery())
            ->paginated(false)
            ->emptyStateHeading('هنوز سفارشی ثبت نشده')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('store')->label('فروشگاه')
                    ->getStateUsing(fn (Order $record) => $record->reseller?->slug ?? 'اصلی'),
                Tables\Columns\TextColumn::make('user.full_name')->label('مشتری'),
                Tables\Columns\TextColumn::make('product.name')->label('محصول'),
                Tables\Columns\TextColumn::make('amount')->label('مبلغ فروش')
                    ->getStateUsing(fn (Order $record) => Money::format((int) ($record->main_price ?? $record->customers_price ?? 0))),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn ($state) => Order::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Order::STATUS_PENDING, Order::STATUS_PROVISIONING => 'warning',
                        Order::STATUS_PAID, Order::STATUS_ACCOUNT_CREATED => 'success',
                        Order::STATUS_FAILED, Order::STATUS_PROVISION_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y-m-d H:i'),
            ]);
    }
}
