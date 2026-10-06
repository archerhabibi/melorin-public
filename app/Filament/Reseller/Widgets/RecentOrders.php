<?php

namespace App\Filament\Reseller\Widgets;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Models\Order;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** B5.1 — آخرین سفارش‌های فروشگاه نماینده (همه‌ی وضعیت‌ها، از همان Scope سرویس Core). */
class RecentOrders extends BaseWidget
{
    use ResolvesCurrentReseller;

    /**
     * Avoid dispatching Filament's internal __lazyLoad hook to the parent
     * page when running older Livewire/Filament combinations.
     */
    protected static bool $isLazy = false;

    protected static ?string $heading = 'آخرین سفارش‌ها';

    protected static ?int $sort = 40;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(app(ResellerDashboardService::class)->recentOrdersQuery(static::currentReseller()))
            ->paginated(false)
            ->emptyStateHeading('هنوز سفارشی ثبت نشده')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('user.full_name')->label('مشتری'),
                Tables\Columns\TextColumn::make('product.name')->label('محصول'),
                Tables\Columns\TextColumn::make('customers_price')->label('مبلغ فروش')
                    ->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state)),
                Tables\Columns\TextColumn::make('profit')->label('سود')
                    ->getStateUsing(fn (Order $record) => Money::format($record->resellerProfit())),
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
