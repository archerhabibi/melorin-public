<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\FinanceResource\Pages;
use App\Models\WalletTransaction;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Finance\ResellerFinanceCenter;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * مرکز مالی نماینده (B5.5) — فقط مشاهده: اعتبار تأمین، گردش اعتبار و صورت‌حساب بازه.
 *
 * هیچ‌چیز این‌جا موجودی را تغییر نمی‌دهد (فقط WalletService، Master W5). Scope واقعی همین‌جاست (getEloquentQuery) و
 * منطقش در Core (ResellerFinanceCenter): فقط حرکت‌های مربوط به اعتبار این فروشگاه، نه خریدهای شخصی صاحب در Main.
 */
class FinanceResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = WalletTransaction::class;

    protected static ?string $slug = 'finance';

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'مالی';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'مرکز مالی';

    protected static ?string $modelLabel = 'حرکت اعتبار';

    protected static ?string $pluralModelLabel = 'گردش اعتبار';

    private static function center(): ResellerFinanceCenter
    {
        return app(ResellerFinanceCenter::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state), true) : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('نوع')
                    ->getStateUsing(fn (WalletTransaction $record) => ResellerFinanceCenter::kindLabels()[ResellerFinanceCenter::kindOf($record)])
                    ->badge()
                    ->color(fn (WalletTransaction $record) => match (ResellerFinanceCenter::kindOf($record)) {
                        ResellerFinanceCenter::KIND_CHARGE => 'success',
                        ResellerFinanceCenter::KIND_ADJUST => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => ((int) $state > 0 ? '+' : '').Money::format((int) $state))
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance_after')
                    ->label('اعتبار پس از حرکت')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('شرح')
                    ->getStateUsing(fn (WalletTransaction $record) => $record->publicDescription())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('wallet_transactions.description', 'like', '%'.addcslashes($search, '%_\\').'%'))
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->label('بازه')
                    ->options(DashboardPeriod::labels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyPeriod($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('kind')
                    ->label('نوع')
                    ->options(ResellerFinanceCenter::kindLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyKind($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('direction')
                    ->label('جهت')
                    ->options(ResellerFinanceCenter::directionLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyDirection($query, $data['value'] ?? null)),
            ])
            ->actions([])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('هنوز حرکتی در اعتبار شما ثبت نشده')
            ->emptyStateDescription('شارژ اعتبار و هزینه‌ی تأمینِ فروش‌ها این‌جا ثبت می‌شود.');
    }

    public static function getEloquentQuery(): Builder
    {
        return self::center()->ledgerQuery(static::currentReseller());
    }

    public static function getWidgets(): array
    {
        return [
            FinanceResource\Widgets\CreditSummary::class,
            FinanceResource\Widgets\LedgerSummary::class,
            FinanceResource\Widgets\StatementPanel::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFinance::route('/')];
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
