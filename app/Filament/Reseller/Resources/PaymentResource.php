<?php

namespace App\Filament\Reseller\Resources;

use App\Support\Money;
use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Services\Core\PaymentService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * طبق تصمیم صریح: تایید «شارژ حساب» مشتری با خودِ نماینده است (چون پول
 * را مستقیم می‌گیرد). این Resource همان کاری را انجام می‌دهد که دکمه‌های
 * این‌لاین ✅/❌ در ربات نماینده انجام می‌دهند — از همان
 * PaymentService::confirmManualByReseller/rejectByReseller عبور می‌کند،
 * فقط یک رابط جایگزین (وب به‌جای تلگرام) برای همان عملیات است. عمداً
 * فقط wallet_owner_type=user را نشان می‌دهد — شارژ اعتبار خودِ نماینده
 * (wallet_owner_type=reseller) اینجا مدیریت نمی‌شود چون تاییدش با ادمین
 * اصلی است، نه این پنل.
 */
class PaymentResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Payment::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'شارژهای در انتظار';

    protected static ?string $modelLabel = 'پرداخت';

    protected static ?string $pluralModelLabel = 'پرداخت‌ها';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')->label('مشتری'),
                Tables\Columns\TextColumn::make('user.telegram_id')->label('شناسه تلگرام'),
                Tables\Columns\TextColumn::make('amount')->label('مبلغ')->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state)),
                Tables\Columns\TextColumn::make('depositor_name')->label('واریزکننده'),
                Tables\Columns\ImageColumn::make('receipt_image')->label('رسید')->size(60),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors(['warning' => 'pending', 'success' => 'confirmed', 'danger' => 'rejected'])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'در انتظار', 'confirmed' => 'تایید‌شده', 'rejected' => 'رد‌شده', default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(['pending' => 'در انتظار', 'confirmed' => 'تایید‌شده', 'rejected' => 'رد‌شده']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('✅ تایید')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (Payment $record) {
                        try {
                            app(PaymentService::class)->confirmManualByReseller($record, static::currentReseller());
                            Notification::make()->title('پرداخت تایید و کیف‌پول مشتری شارژ شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('❌ رد')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (Payment $record) {
                        try {
                            app(PaymentService::class)->rejectByReseller($record, static::currentReseller());
                            Notification::make()->title('پرداخت رد شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    /**
     * طبق «هیچ Query مربوط به Reseller بدون Scope مجاز نیست» — این
     * تنها Scope واقعیِ امنیتی است؛ خودِ اکشن‌های approve/reject هم
     * جداگانه توسط PaymentService::confirmManualByReseller enforce
     * می‌شوند (دفاع لایه‌به‌لایه، نه فقط پنهان‌کردن ردیف‌های دیگران در UI).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('reseller_id', static::currentReseller()->id)
            ->where('wallet_owner_type', 'user');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
