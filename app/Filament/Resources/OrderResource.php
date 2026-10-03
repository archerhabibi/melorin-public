<?php

namespace App\Filament\Resources;

use App\Support\Money;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\Core\Provisioning\FailedOrderRecovery;
use App\Services\Core\Provisioning\RecoveryResult;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
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

    protected static ?int $navigationSort = 1;

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
                    ->getStateUsing(fn (Order $record) => Money::format((int) ($record->main_price ?? $record->customers_price))),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => Order::statusLabels()[$state] ?? $state)
                    ->colors([
                        'warning' => ['pending', 'provisioning'], 'info' => 'paid',
                        'success' => 'account_created', 'danger' => ['failed', 'provision_failed'], 'gray' => 'refunded',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(Order::statusLabels()),
                Tables\Filters\SelectFilter::make('sales_channel')->label('کانال فروش')->options([
                    'main_bot' => 'ربات اصلی', 'reseller_bot' => 'ربات نماینده',
                    'website' => 'سایت', 'panel' => 'پنل مدیریت',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('retryProvisioning')
                    ->label('🔁 تلاش مجدد')
                    ->color('warning')
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PROVISION_FAILED)
                    ->requiresConfirmation()
                    ->modalDescription('سرویس بدون هیچ کسر مالی جدید دوباره ساخته/تمدید می‌شود. اگر تلاش‌های قبلی ممکن است روی پنل اثر کرده باشند، ابتدا پنل را بررسی کنید.')
                    ->action(fn (Order $record) => static::runRetry($record)),

                Tables\Actions\Action::make('refundOrder')
                    ->label('↩️ بازگشت وجه')
                    ->color('gray')
                    ->visible(fn (Order $record) => $record->status === Order::STATUS_PROVISION_FAILED)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Order $record) => static::refundSummary($record))
                    ->action(fn (Order $record) => static::runRefund($record)),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn ($state) => $state === null ? '—' : Money::format((int) $state);

        return $infolist->schema([
            Infolists\Components\Section::make('سفارش')->columns(3)->schema([
                Infolists\Components\TextEntry::make('id')->label('#'),
                Infolists\Components\TextEntry::make('status')->label('وضعیت')->badge()
                    ->formatStateUsing(fn ($state) => Order::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'account_created' => 'success',
                        'provision_failed', 'failed' => 'danger',
                        'pending', 'provisioning' => 'warning',
                        'paid' => 'info',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('created_at')->label('تاریخ')->dateTime('Y-m-d H:i'),
                Infolists\Components\TextEntry::make('user.full_name')->label('کاربر')->placeholder('—'),
                Infolists\Components\TextEntry::make('product.name')->label('محصول')->placeholder('—'),
                Infolists\Components\TextEntry::make('reseller.user.full_name')->label('نماینده')->placeholder('—'),
                Infolists\Components\TextEntry::make('main_price')->label('main_price')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('customers_price')->label('customers_price')->formatStateUsing($money),
                Infolists\Components\TextEntry::make('reseller_price')->label('reseller_price')->formatStateUsing($money),
            ]),
            Infolists\Components\Section::make('Provisioning')->columns(3)->schema([
                Infolists\Components\TextEntry::make('provision_attempts')->label('تعداد تلاش')->placeholder('0'),
                Infolists\Components\TextEntry::make('next_provision_retry_at')->label('تلاش خودکار بعدی')->dateTime('Y-m-d H:i')->placeholder('—'),
                Infolists\Components\TextEntry::make('renews_account_id')->label('تمدید اکانت #')->placeholder('— (سفارش خرید)'),
                Infolists\Components\TextEntry::make('failure_reason')->label('دلیل آخرین شکست')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Order::query()->where('status', Order::STATUS_PROVISION_FAILED)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** خلاصه‌ی مبالغی که بازگشت وجه از روی اسنپ‌شات خودِ سفارش پس می‌دهد */
    public static function refundSummary(Order $order): string
    {
        $customer = (int) ($order->customers_price ?? $order->main_price ?? 0);
        $text = 'مشتری: '.Money::format($customer);

        if ($order->isResellerContext()) {
            $text .= ' | نماینده (reseller_price): '.Money::format((int) $order->reseller_price);
        }

        return $text.' — از روی قیمت‌های ثبت‌شده‌ی همین سفارش، نه قیمت امروز.';
    }

    public static function runRetry(Order $order): RecoveryResult
    {
        // ادمین آگاهانه تصمیم می‌گیرد → سقف تلاش نادیده گرفته می‌شود
        try {
            $result = app(FailedOrderRecovery::class)->retry($order, force: true);
        } catch (\Throwable $e) {
            $result = new RecoveryResult(RecoveryResult::FAILED, $e->getMessage());
        }

        static::notifyResult($result, 'تلاش مجدد');

        return $result;
    }

    public static function runRefund(Order $order): RecoveryResult
    {
        $result = app(FailedOrderRecovery::class)->refund($order);

        static::notifyResult($result, 'بازگشت وجه');

        return $result;
    }

    protected static function notifyResult(RecoveryResult $result, string $what): void
    {
        $notification = Notification::make()->title("{$what}: {$result->message}");

        match ($result->status) {
            RecoveryResult::SUCCEEDED => $notification->success(),
            RecoveryResult::SKIPPED => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }

    // ---- جستجوی سراسری (B1.3) ----
    protected static ?string $recordTitleAttribute = 'id';

    public static function getGloballySearchableAttributes(): array
    {
        return ['id', 'user.full_name', 'user.email'];
    }

    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('user');
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        return 'سفارش #'.$record->id;
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        return array_filter(['مشتری' => $record->user?->full_name]);
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
