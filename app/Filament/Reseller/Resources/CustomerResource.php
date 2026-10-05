<?php

namespace App\Filament\Reseller\Resources;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Filament\Reseller\Resources\CustomerResource\Pages;
use App\Models\Account;
use App\Models\Order;
use App\Models\User;
use App\Services\Resellers\Customers\CustomerSegment;
use App\Services\Resellers\Customers\ResellerCustomerDirectory;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * مدیریت مشتریان نماینده (B5.2) — فقط مشاهده.
 *
 * نماینده نباید هویت مرکزی کاربر (وضعیت کلی/telegram_id) را از این پنل تغییر دهد؛ آن مدیریت مختص ادمین اصلی است
 * (بند ۱۹ سند Spec). همه‌ی منطق و عددها از ResellerCustomerDirectory (Core) می‌آیند؛ این‌جا فقط نمایش است.
 *
 * Scope واقعی همین‌جاست (getEloquentQuery): فقط Userهایی که CustomerAccount در فروشگاه «همین» نماینده دارند.
 * ستون‌های سفارش/سرویس/موجودی همه از همان فروشگاه‌اند و با Subquery خوانده می‌شوند (بدون N+1 و بدون ساخت Wallet).
 */
class CustomerResource extends Resource
{
    use ResolvesCurrentReseller;

    protected static ?string $model = User::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'مشتریان و فروش';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'مشتریان';

    protected static ?string $modelLabel = 'مشتری';

    protected static ?string $pluralModelLabel = 'مشتریان';

    private const MEMBERSHIP_LABELS = ['active' => 'فعال', 'disabled' => 'غیرفعال', 'blocked' => 'مسدود'];

    private const SERVICE_STATE_LABELS = [
        'active' => 'فعال', 'expiring' => 'رو‌به‌انقضا', 'expired' => 'منقضی',
        'disabled' => 'غیرفعال', 'suspended' => 'معلق', 'deleted' => 'حذف‌شده',
    ];

    private static function directory(): ResellerCustomerDirectory
    {
        return app(ResellerCustomerDirectory::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('مشتری')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'phone', 'telegram_id'])
                    ->description(fn (User $record) => collect([$record->email, $record->phone, $record->telegram_id ? 'تلگرام: '.$record->telegram_id : null])->filter()->first()),
                Tables\Columns\TextColumn::make('membership_status')
                    ->label('عضویت')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::MEMBERSHIP_LABELS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'blocked' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('active_services_count')
                    ->label('سرویس فعال')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('next_expiry_at')
                    ->label('نزدیک‌ترین انقضا')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => self::expiryLabel($state))
                    ->color(fn ($state) => self::expiryIsNear($state) ? 'warning' : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('wallet_balance')
                    ->label('موجودی کیف‌پول')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label('سفارش‌ها')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_spent')
                    ->label('مجموع خرید')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_order_at')
                    ->label('آخرین خرید')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : null)
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label('عضویت از')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : null)
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('joined_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('segment')
                    ->label('بخش مشتریان')
                    ->options(CustomerSegment::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $segment = CustomerSegment::fromInput($data['value'] ?? null);

                        return $segment ? self::directory()->applySegment($query, static::currentReseller(), $segment) : $query;
                    }),
                Tables\Filters\SelectFilter::make('membership')
                    ->label('وضعیت عضویت')
                    ->options(self::MEMBERSHIP_LABELS)
                    ->query(fn (Builder $query, array $data): Builder => self::directory()->applyMembershipStatus($query, static::currentReseller(), $data['value'] ?? null)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('پرونده'),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('هنوز مشتری‌ای ندارید')
            ->emptyStateDescription('مشتریانی که از فروشگاه، سایت یا ربات شما خرید کنند این‌جا ظاهر می‌شوند.');
    }

    /** پرونده‌ی مشتری: مشخصات، خلاصه‌ی مالی، سرویس‌ها و آخرین سفارش‌ها (فقط همین فروشگاه). */
    public static function infolist(Infolist $infolist): Infolist
    {
        $record = $infolist->getRecord();
        $profile = $record instanceof User ? self::directory()->profile(static::currentReseller(), $record) : null;

        abort_if($profile === null, 404);

        $services = $profile->services->map(fn (Account $a) => [
            'product' => $a->product?->name ?? '—',
            'state' => self::SERVICE_STATE_LABELS[$a->displayState()] ?? $a->displayState(),
            'expires' => $a->expires_at ? JalaliDate::format($a->expires_at).' ('.$a->remainingDays().' روز مانده)' : 'بدون انقضا',
            'traffic' => $a->traffic_gb === null
                ? 'نامحدود'
                : rtrim(rtrim(number_format($a->usedTrafficGb(), 2), '0'), '.').' از '.rtrim(rtrim(number_format((float) $a->traffic_gb, 2), '0'), '.').' گیگابایت',
        ])->all();

        $orders = $profile->recentOrders->map(fn (Order $o) => [
            'id' => '#'.$o->id,
            'product' => $o->product?->name ?? '—',
            'kind' => $o->isRenewal() ? 'تمدید' : 'خرید',
            'price' => $o->customers_price === null ? '—' : Money::format((int) $o->customers_price),
            'status' => Order::statusLabels()[$o->status] ?? $o->status,
            'date' => JalaliDate::format($o->created_at),
        ])->all();

        return $infolist->schema([
            Section::make('مشخصات')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('p_name')->label('نام')->state($profile->user->full_name ?: '—'),
                    TextEntry::make('p_membership')->label('وضعیت عضویت')->badge()
                        ->state(self::MEMBERSHIP_LABELS[$profile->membershipStatus] ?? $profile->membershipStatus)
                        ->color($profile->isActiveMember() ? 'success' : ($profile->membershipStatus === 'blocked' ? 'danger' : 'gray')),
                    TextEntry::make('p_joined')->label('عضویت از')->state($profile->joinedAt ? JalaliDate::format($profile->joinedAt) : '—'),
                    TextEntry::make('p_email')->label('ایمیل')->state($profile->user->email ?: '—'),
                    TextEntry::make('p_phone')->label('موبایل')->state($profile->user->phone ?: '—'),
                    TextEntry::make('p_telegram')->label('شناسه تلگرام')->state($profile->user->telegram_id ?: '—'),
                ]),
            ]),
            Section::make('خلاصه‌ی مالی (همین فروشگاه)')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('p_wallet')->label('موجودی کیف‌پول')->state(Money::format($profile->walletBalance)),
                    TextEntry::make('p_revenue')->label('مجموع خرید')->state(Money::format($profile->revenue)),
                    TextEntry::make('p_profit')->label('سود شما از این مشتری')->state(Money::format($profile->profit)),
                    TextEntry::make('p_orders')->label('سفارش‌ها')->state(number_format($profile->orders).' ('.number_format($profile->renewals).' تمدید)'),
                    TextEntry::make('p_last')->label('آخرین خرید')->state($profile->lastOrderAt ? JalaliDate::format($profile->lastOrderAt) : '—'),
                ]),
            ]),
            Section::make('سرویس‌ها')->schema([
                RepeatableEntry::make('p_services')->label('')->state($services)->grid(2)->schema([
                    TextEntry::make('product')->label('محصول'),
                    TextEntry::make('state')->label('وضعیت')->badge(),
                    TextEntry::make('expires')->label('انقضا'),
                    TextEntry::make('traffic')->label('مصرف'),
                ])->placeholder('این مشتری هنوز سرویسی ندارد.')->hidden($services === []),
                TextEntry::make('p_no_services')->label('')->state('این مشتری هنوز سرویسی ندارد.')->visible($services === []),
            ]),
            Section::make('آخرین سفارش‌ها')->schema([
                RepeatableEntry::make('p_orders_list')->label('')->state($orders)->grid(2)->schema([
                    TextEntry::make('id')->label('سفارش'),
                    TextEntry::make('product')->label('محصول'),
                    TextEntry::make('kind')->label('نوع'),
                    TextEntry::make('price')->label('مبلغ'),
                    TextEntry::make('status')->label('وضعیت')->badge(),
                    TextEntry::make('date')->label('تاریخ'),
                ])->hidden($orders === []),
                TextEntry::make('p_no_orders')->label('')->state('سفارشی ثبت نشده است.')->visible($orders === []),
            ]),
        ]);
    }

    private static function expiryLabel(mixed $state): ?string
    {
        if (! $state) {
            return null;
        }

        $at = CarbonImmutable::parse($state);
        $days = max(0, (int) ceil(($at->getTimestamp() - now()->getTimestamp()) / 86400));

        return JalaliDate::format($at).' ('.$days.' روز)';
    }

    private static function expiryIsNear(mixed $state): bool
    {
        return $state && CarbonImmutable::parse($state)->lte(now()->addDays(ResellerCustomerDirectory::EXPIRING_DAYS));
    }

    /** طبق «اصل طلایی امنیت»: تنها Scope واقعی همین‌جاست؛ منطق آن در Core (ResellerCustomerDirectory::query). */
    public static function getEloquentQuery(): Builder
    {
        return self::directory()->query(static::currentReseller());
    }

    // ---- جستجوی سراسری (B1.3): روی getEloquentQuery اسکوپ‌شده‌ی همین فروشگاه ----
    protected static ?string $recordTitleAttribute = 'full_name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['full_name', 'email', 'phone', 'telegram_id'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->full_name ?: ($record->email ?: 'مشتری #'.$record->id);
    }

    public static function getWidgets(): array
    {
        return [CustomerResource\Widgets\CustomerSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
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
