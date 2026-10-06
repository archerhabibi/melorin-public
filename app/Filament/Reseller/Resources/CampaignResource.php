<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CampaignResource\Pages;
use App\Models\Broadcast;
use App\Services\Resellers\Dashboard\DashboardPeriod;
use App\Services\Resellers\Marketing\ResellerMarketingCenter;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * سابقه‌ی کمپین‌های پیام نماینده (B5.6) — فقط مشاهده. ارسال پیام جدید فقط از صفحه‌ی «پیام همگانی» است.
 * فقط پیام‌های خودِ نماینده؛ پیام همگانیِ ادمین اصلی و نمایندگان دیگر هرگز دیده نمی‌شود.
 */
class CampaignResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = Broadcast::class;

    protected static ?string $slug = 'campaigns';

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'بازاریابی';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'سابقه‌ی کمپین‌های پیام';

    protected static ?string $modelLabel = 'کمپین پیام';

    protected static ?string $pluralModelLabel = 'کمپین‌های پیام';

    private static function center(): ResellerMarketingCenter
    {
        return app(ResellerMarketingCenter::class);
    }

    /** نرخ تحویل صحیح (گرد به نزدیک‌ترین)؛ بدون گیرنده ⇒ null */
    public static function deliveryPercent(Broadcast $record): ?int
    {
        $total = (int) $record->total_recipients;

        return $total <= 0 ? null : intdiv((int) $record->sent_count * 100 + intdiv($total, 2), $total);
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            'sending' => 'warning',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state), true) : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('message')
                    ->label('پیام')
                    ->formatStateUsing(fn ($state) => Str::limit(trim((string) $state), 80))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('broadcasts.message', 'like', '%'.addcslashes($search, '%_\\').'%'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ResellerMarketingCenter::campaignStatusLabels()[$state] ?? $state)
                    ->color(fn ($state) => self::statusColor((string) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_recipients')->label('گیرنده')->formatStateUsing(fn ($state) => number_format((int) $state))->sortable(),
                Tables\Columns\TextColumn::make('sent_count')->label('ارسال‌شده')->formatStateUsing(fn ($state) => number_format((int) $state))->sortable(),
                Tables\Columns\TextColumn::make('failed_count')->label('ناموفق')->formatStateUsing(fn ($state) => number_format((int) $state))->color(fn ($state) => (int) $state > 0 ? 'danger' : null)->sortable(),
                Tables\Columns\TextColumn::make('delivery')
                    ->label('نرخ تحویل')
                    ->getStateUsing(fn (Broadcast $record) => self::deliveryPercent($record) === null ? '—' : number_format(self::deliveryPercent($record)).'٪'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('period')
                    ->label('بازه')
                    ->options(DashboardPeriod::labels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyCampaignPeriod($query, $data['value'] ?? null)),
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(ResellerMarketingCenter::campaignStatusLabels())
                    ->query(fn (Builder $query, array $data): Builder => self::center()->applyCampaignStatus($query, $data['value'] ?? null)),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('جزئیات')])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('هنوز پیام همگانی نفرستاده‌اید')
            ->emptyStateDescription('پیام‌هایی که از «پیام همگانی» می‌فرستید همراه با نتیجه‌ی ارسال این‌جا ثبت می‌شود.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        /** @var Broadcast $b */
        $b = $infolist->getRecord();
        $rate = self::deliveryPercent($b);

        return $infolist->schema([
            Section::make('نتیجه‌ی ارسال')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('c_status')->label('وضعیت')->badge()
                        ->state(ResellerMarketingCenter::campaignStatusLabels()[$b->status] ?? $b->status)
                        ->color(self::statusColor((string) $b->status)),
                    TextEntry::make('c_created')->label('ایجاد')->state($b->created_at ? JalaliDate::format(CarbonImmutable::parse($b->created_at), true) : '—'),
                    TextEntry::make('c_finished')->label('پایان')->state($b->finished_at ? JalaliDate::format(CarbonImmutable::parse($b->finished_at), true) : '—'),
                    TextEntry::make('c_total')->label('گیرنده')->state(number_format((int) $b->total_recipients)),
                    TextEntry::make('c_sent')->label('ارسال‌شده')->state(number_format((int) $b->sent_count)),
                    TextEntry::make('c_failed')->label('ناموفق')->state(number_format((int) $b->failed_count)),
                    TextEntry::make('c_rate')->label('نرخ تحویل')->state($rate === null ? '—' : number_format($rate).'٪'),
                    TextEntry::make('c_progress')->label('پیشرفت')->state(number_format($b->progressPercent()).'٪'),
                ]),
            ]),
            Section::make('متن پیام')->schema([
                TextEntry::make('c_message')->label('')->state((string) $b->message)->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::center()->campaignQuery(static::currentReseller());
    }

    public static function getWidgets(): array
    {
        return [CampaignResource\Widgets\CampaignSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'view' => Pages\ViewCampaign::route('/{record}'),
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
