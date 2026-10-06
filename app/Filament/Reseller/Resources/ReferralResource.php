<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\ReferralResource\Pages;
use App\Models\CustomerAccount;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * معرفی (Referral) در مرکز بازاریابی نماینده (B5.6) — فقط مشاهده.
 *
 * هر ردیف یک عضوِ معرفی‌شده‌ی همین فروشگاه است. پاداش/کمیسیون را Core هنگام خرید پرداخت می‌کند (ReferralService/CommissionService)؛
 * این صفحه فقط وضعیت جذب و تبدیل را نشان می‌دهد. Scope واقعی در getEloquentQuery (منطق در ResellerMarketingCenter).
 */
class ReferralResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = CustomerAccount::class;

    protected static ?string $slug = 'referrals';

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'بازاریابی';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'معرفی مشتریان';

    protected static ?string $modelLabel = 'عضو معرفی‌شده';

    protected static ?string $pluralModelLabel = 'اعضای معرفی‌شده';

    private static function center(): ResellerMarketingCenter
    {
        return app(ResellerMarketingCenter::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')
                    ->label('عضو معرفی‌شده')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'telegram_id'])
                    ->description(fn (CustomerAccount $record) => collect([$record->user?->email, $record->user?->telegram_id ? 'تلگرام: '.$record->user->telegram_id : null])->filter()->first()),
                Tables\Columns\TextColumn::make('user.referrer.full_name')
                    ->label('معرف')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'telegram_id']),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('عضویت')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('state')
                    ->label('وضعیت')
                    ->getStateUsing(fn (CustomerAccount $record) => ResellerMarketingCenter::stateLabels()[(int) $record->purchases > 0 ? ResellerMarketingCenter::STATE_CONVERTED : ResellerMarketingCenter::STATE_WAITING])
                    ->badge()
                    ->color(fn (CustomerAccount $record) => (int) $record->purchases > 0 ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('purchases')
                    ->label('خریدها')
                    ->formatStateUsing(fn ($state) => number_format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('spent')
                    ->label('مجموع خرید')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_purchase_at')
                    ->label('اولین خرید')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : '—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('joined')
                    ->label('بازه‌ی عضویت')
                    ->options(DashboardPeriod::labels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyJoinedPeriod($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('state')
                    ->label('وضعیت')
                    ->options(ResellerMarketingCenter::stateLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyState($query, $data['value'] ?? null)),
            ])
            ->actions([])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('هنوز عضو معرفی‌شده‌ای ندارید')
            ->emptyStateDescription('وقتی مشتری با لینک معرفیِ یک مشتری دیگر وارد فروشگاه شما شود، این‌جا دیده می‌شود.');
    }

    public static function getEloquentQuery(): Builder
    {
        return self::center()->referralQuery(static::currentReseller());
    }

    public static function getWidgets(): array
    {
        return [
            ReferralResource\Widgets\ReferralSummary::class,
            ReferralResource\Widgets\TopInviters::class,
        ];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReferrals::route('/')];
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
