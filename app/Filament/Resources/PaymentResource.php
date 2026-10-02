<?php

namespace App\Filament\Resources;

use App\Support\Money;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Services\Core\PaymentService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * بررسی و تایید/رد رسیدهای پرداخت (بند ۱۲ سند نیازمندی). این Resource
 * هرگز مستقیم روی payments یا wallets چیزی می‌نویسد — تمام عملیات از
 * طریق PaymentService انجام می‌شود (اصل بند ۳۴).
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?string $navigationLabel = 'پرداخت‌ها';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('user')
                ->label('کاربر تلگرام')
                ->content(fn (Payment $record) => $record->user->full_name.' (شناسه: '.$record->user->telegram_id.')'),

            Forms\Components\Placeholder::make('depositor_name')
                ->label('نام صاحب کارتِ واریزکننده')
                ->content(fn (Payment $record) => $record->depositor_name ?: '— وارد نشده —'),

            Forms\Components\Placeholder::make('amount')
                ->label('مبلغ')
                ->content(fn (Payment $record) => Money::format((int) $record->amount)),

            Forms\Components\Placeholder::make('method')
                ->label('روش پرداخت')
                ->content(fn (Payment $record) => $record->paymentMethod->name),

            Forms\Components\Placeholder::make('receipt_image')
                ->label('رسید پرداخت')
                ->content(fn (Payment $record) => $record->receipt_image
                    ? new HtmlString(
                        '<img src="'.route('admin.payments.receipt', $record).'" style="max-width:400px;border-radius:8px" alt="رسید پرداخت">'
                    )
                    : 'رسیدی ثبت نشده است.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('user.full_name')->label('کاربر تلگرام')->searchable(),
                Tables\Columns\TextColumn::make('user.telegram_id')->label('شناسه تلگرام')->searchable(),
                Tables\Columns\TextColumn::make('depositor_name')->label('نام واریزکننده')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('مبلغ')->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state))->sortable(),
                Tables\Columns\TextColumn::make('paymentMethod.name')->label('روش پرداخت'),
                Tables\Columns\TextColumn::make('purpose')->label('بابت')
                    ->formatStateUsing(fn ($state) => $state === 'wallet_charge' ? 'شارژ کیف پول' : 'سفارش'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'confirmed',
                        'danger' => 'rejected',
                        'gray' => 'refunded',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => 'در انتظار بررسی',
                        'confirmed' => 'تایید شده',
                        'rejected' => 'رد شده',
                        'refunded' => 'بازگشت‌شده',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'pending' => 'در انتظار بررسی',
                        'confirmed' => 'تایید شده',
                        'rejected' => 'رد شده',
                        'refunded' => 'بازگشت‌شده',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('confirm')
                    ->label('✅ تایید پرداخت')
                    ->color('success')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalDescription('با تایید، مبلغ بلافاصله به کیف پول کاربر واریز می‌شود.')
                    ->action(function (Payment $record) {
                        try {
                            app(PaymentService::class)->confirmManual($record, Auth::guard('admin')->user());

                            Notification::make()->title('پرداخت تایید و کیف پول شارژ شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('❌ رد پرداخت')
                    ->color('danger')
                    ->visible(fn (Payment $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('دلیل رد کردن')->required(),
                    ])
                    ->action(function (Payment $record, array $data) {
                        try {
                            app(PaymentService::class)->reject($record, Auth::guard('admin')->user(), $data['reason']);

                            Notification::make()->title('پرداخت رد شد.')->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('refund')
                    ->label('↩️ بازگشت وجه')
                    ->color('gray')
                    ->visible(fn (Payment $record) => $record->status === 'confirmed' && $record->purpose === 'wallet_charge')
                    ->requiresConfirmation()
                    ->modalDescription('این مبلغ از کیف پول کاربر کسر خواهد شد. اگر کاربر قبلاً آن را خرج کرده باشد، این عملیات با خطا مواجه می‌شود.')
                    ->action(function (Payment $record) {
                        try {
                            app(PaymentService::class)->refund($record, Auth::guard('admin')->user());

                            Notification::make()->title('وجه با موفقیت بازگشت داده شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // پرداخت‌ها فقط از طریق ربات/سایت (PaymentService::initiate) ساخته
        // می‌شوند، نه دستی از پنل — طبق اصل بند ۳۴.
        return false;
    }
}
