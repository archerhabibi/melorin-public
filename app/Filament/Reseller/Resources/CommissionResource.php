<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CommissionResource\Pages;
use App\Models\Commission;
use App\Models\Order;
use App\Services\Resellers\Commissions\ResellerCommissionCenter;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * مرکز کمیسیون نماینده (B5.4) — فقط مشاهده.
 *
 * کمیسیون و پاداش معرفی را Core (CommissionService/ReferralService) هنگام خرید محاسبه و واریز می‌کند؛ این پنل هیچ‌چیز
 * محاسبه یا ویرایش نمی‌کند. همه‌ی منطق و عددها از ResellerCommissionCenter می‌آیند؛ این‌جا فقط نمایش است.
 * Scope واقعی همین‌جاست (getEloquentQuery): فقط کمیسیون سفارش‌های «همین» فروشگاه.
 */
class CommissionResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Commission::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'کمیسیون‌ها';

    protected static ?string $modelLabel = 'کمیسیون';

    protected static ?string $pluralModelLabel = 'کمیسیون‌ها';

    private static function center(): ResellerCommissionCenter
    {
        return app(ResellerCommissionCenter::class);
    }

    private static function percentLabel(mixed $rate): string
    {
        return $rate === null ? '—' : (rtrim(rtrim((string) $rate, '0'), '.') ?: '0').'٪';
    }

    private static function date(mixed $state, bool $withTime = false): ?string
    {
        return $state ? JalaliDate::format(CarbonImmutable::parse($state), $withTime) : null;
    }

    /** نشان «نیازمند توجه» یک رکورد (بازگشت‌شده / بیشتر از سود)؛ همان تعریف فیلتر و شمارنده. */
    public static function attentionOf(Commission $record): ?string
    {
        if ($record->order_status === Order::STATUS_REFUNDED) {
            return ResellerCommissionCenter::attentionLabels()[ResellerCommissionCenter::ATTENTION_REFUNDED];
        }

        // order_profit ستون محاسبه‌شده‌ی query() است؛ اگر رکوردی بدون آن رسید (مثلاً مدل خام) سود «نامعلوم» است
        // و ادعای «بیشتر از سود» نمی‌شود کرد.
        $profit = $record->getAttribute('order_profit');

        if ($record->type === Commission::TYPE_ONGOING && $profit !== null && (int) $record->amount > (int) $profit) {
            return ResellerCommissionCenter::attentionLabels()[ResellerCommissionCenter::ATTENTION_EXCEEDS_PROFIT];
        }

        return null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ پرداخت')
                    ->formatStateUsing(fn ($state) => self::date($state, true))
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('نوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Commission::typeLabels()[$state] ?? $state)
                    ->color(fn ($state) => $state === Commission::TYPE_ONGOING ? 'primary' : 'info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('referrer.full_name')
                    ->label('معرف')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'telegram_id'])
                    ->description(fn (Commission $record) => collect([$record->referrer?->email, $record->referrer?->telegram_id ? 'تلگرام: '.$record->referrer->telegram_id : null])->filter()->first()),
                Tables\Columns\TextColumn::make('referredUser.full_name')
                    ->label('مشتریِ معرفی‌شده')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'telegram_id']),
                Tables\Columns\TextColumn::make('order_id')
                    ->label('سفارش')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_amount')
                    ->label('مبنای محاسبه')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : Money::format((int) $state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('نرخ')
                    ->formatStateUsing(fn ($state) => self::percentLabel($state))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_profit')
                    ->label('سود سفارش')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Commission::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => $state === Commission::STATUS_PAID ? 'success' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('attention')
                    ->label('توجه')
                    ->getStateUsing(fn (Commission $record) => self::attentionOf($record))
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->label('بازه‌ی پرداخت')
                    ->options(DashboardPeriod::labels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyPeriod($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('نوع')
                    ->options(ResellerCommissionCenter::kindLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyKind($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(Commission::statusLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyStatus($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('attention')
                    ->label('نیازمند توجه')
                    ->options(ResellerCommissionCenter::attentionLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyAttention($query, $data['value'] ?? null)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('جزئیات'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('هنوز کمیسیونی پرداخت نشده')
            ->emptyStateDescription('وقتی مشتریِ معرفی‌شده‌ای از فروشگاه شما خرید کند، کمیسیون معرف این‌جا ثبت می‌شود.');
    }

    /** جزئیات یک کمیسیون: پرداخت، معرف و مشتری، و سفارش با سود پیش و پس از کمیسیون. */
    public static function infolist(Infolist $infolist): Infolist
    {
        /** @var Commission $c */
        $c = $infolist->getRecord();
        $order = $c->order;
        $isCommission = $c->type === Commission::TYPE_ONGOING;
        $profit = (int) $c->order_profit;
        $notes = array_values(array_filter([
            $c->order_status === Order::STATUS_REFUNDED
                ? 'این سفارش بازگشت خورده است؛ طبق قرارداد، کمیسیون/پاداش پرداخت‌شده به‌صورت خودکار برنمی‌گردد.'
                : null,
            $isCommission && (int) $c->amount > $profit
                ? 'مبلغ این کمیسیون از سود همین سفارش بیشتر است؛ نرخ کمیسیون را مدیریت Melorin تعیین می‌کند.'
                : null,
        ]));

        return $infolist->schema([
            Section::make('پرداخت')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('c_type')->label('نوع')->badge()
                        ->state(Commission::typeLabels()[$c->type] ?? $c->type)
                        ->color($isCommission ? 'primary' : 'info'),
                    TextEntry::make('c_status')->label('وضعیت')->badge()
                        ->state(Commission::statusLabels()[$c->status] ?? $c->status)
                        ->color($c->status === Commission::STATUS_PAID ? 'success' : 'warning'),
                    TextEntry::make('c_date')->label('تاریخ پرداخت')->state(self::date($c->created_at, true) ?? '—'),
                    TextEntry::make('c_amount')->label('مبلغ')->state(Money::format((int) $c->amount)),
                    TextEntry::make('c_rate')->label('نرخ (در زمان خرید)')->state(self::percentLabel($c->commission_rate)),
                    TextEntry::make('c_base')->label('مبنای محاسبه')->state($c->base_amount === null ? '—' : Money::format((int) $c->base_amount)),
                ]),
            ]),
            Section::make('معرف و مشتریِ معرفی‌شده')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('r_name')->label('معرف')->state($c->referrer?->full_name ?: '—'),
                    TextEntry::make('r_email')->label('ایمیل معرف')->state($c->referrer?->email ?: '—'),
                    TextEntry::make('r_telegram')->label('تلگرام معرف')->state($c->referrer?->telegram_id ?: '—'),
                    TextEntry::make('u_name')->label('مشتریِ معرفی‌شده')->state($c->referredUser?->full_name ?: '—'),
                    TextEntry::make('u_email')->label('ایمیل مشتری')->state($c->referredUser?->email ?: '—'),
                    TextEntry::make('u_telegram')->label('تلگرام مشتری')->state($c->referredUser?->telegram_id ?: '—'),
                ]),
            ]),
            Section::make('سفارش')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('o_id')->label('سفارش')->state('#'.$c->order_id),
                    TextEntry::make('o_product')->label('محصول')->state($order?->product?->name ?: '—'),
                    TextEntry::make('o_status')->label('وضعیت سفارش')->badge()
                        ->state(Order::statusLabels()[$c->order_status] ?? ($c->order_status ?: '—')),
                    TextEntry::make('o_price')->label('مبلغ فروش')->state($order?->customers_price === null ? '—' : Money::format((int) $order->customers_price)),
                    TextEntry::make('o_supply')->label('هزینه‌ی تأمین')->state($order?->reseller_price === null ? '—' : Money::format((int) $order->reseller_price)),
                    TextEntry::make('o_profit')->label('سود سفارش')->state(Money::format($profit)),
                    TextEntry::make('o_net')->label('سود پس از این کمیسیون')
                        ->state($isCommission ? Money::format($profit - (int) $c->amount) : '—')
                        ->color($isCommission && (int) $c->amount > $profit ? 'danger' : null),
                ]),
                TextEntry::make('c_notes')->label('')->state($notes)->listWithLineBreaks()->color('warning')->visible($notes !== []),
            ]),
        ]);
    }

    /** طبق «اصل طلایی امنیت»: تنها Scope واقعی همین‌جاست؛ منطق آن در Core (ResellerCommissionCenter::query). */
    public static function getEloquentQuery(): Builder
    {
        return self::center()->query(static::currentReseller());
    }

    public static function getWidgets(): array
    {
        return [
            CommissionResource\Widgets\CommissionSummary::class,
            CommissionResource\Widgets\TopReferrers::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommissions::route('/'),
            'view' => Pages\ViewCommission::route('/{record}'),
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
