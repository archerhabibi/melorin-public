<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\MoneyInput;
use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Admin\Customers\CustomerStoreRow;
use App\Services\Admin\Customers\GlobalCustomerDirectory;
use App\Services\Admin\Customers\GlobalCustomerIdentity;
use App\Services\Admin\Customers\GlobalCustomerProfile;
use App\Services\Admin\Customers\GlobalCustomerSegment;
use App\Services\Core\WalletService;
use App\Support\JalaliDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * مدیریت کاربران (بند ۱۴ سند نیازمندی) + نمای سراسری مشتری (B7.2).
 *
 * فهرست و پرونده «همه‌ی فروشگاه‌ها» را یک‌جا نشان می‌دهند (هر فروشگاه جدا؛ R8)؛ همه‌ی عددها از
 * `GlobalCustomerDirectory` (Core، فقط‌خواندنی) می‌آیند. تنها عملیات نوشتنی همان‌های پیشین‌اند
 * (ویرایش وضعیت/نام/موبایل و «تنظیم موجودی» Main با Audit در WalletService) و چیزی به آن‌ها اضافه نشده است.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'کاربر';

    protected static ?string $pluralModelLabel = 'کاربران و مشتریان';

    private const USER_STATUS_LABELS = ['active' => 'فعال', 'disabled' => 'غیرفعال', 'blocked' => 'مسدود'];

    private const SERVICE_STATE_LABELS = [
        'active' => 'فعال', 'expiring' => 'رو‌به‌انقضا', 'expired' => 'منقضی',
        'disabled' => 'غیرفعال', 'suspended' => 'معلق', 'deleted' => 'حذف‌شده',
    ];

    private const JOINED_FROM_LABELS = ['bot' => 'ربات', 'website' => 'وب‌سایت', 'reseller_bot' => 'ربات نماینده', 'panel' => 'پنل'];

    private const TICKET_STATUS_LABELS = ['open' => 'باز', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'];

    private const PAYMENT_STATUS_LABELS = [
        'pending' => 'در انتظار', 'confirmed' => 'تأییدشده', 'approved' => 'تأییدشده', 'paid' => 'پرداخت‌شده',
        'rejected' => 'ردشده', 'failed' => 'ناموفق', 'refunded' => 'بازگشت‌شده',
    ];

    private const PAYMENT_PURPOSE_LABELS = ['order' => 'خرید', 'wallet_charge' => 'شارژ کیف‌پول'];

    private static function directory(): GlobalCustomerDirectory
    {
        return app(GlobalCustomerDirectory::class);
    }

    /** کوئری فهرست/پرونده: ستون‌های محاسبه‌شده‌ی Core (Subquery، بدون N+1). منطق در Core است نه این‌جا. */
    public static function getEloquentQuery(): Builder
    {
        return self::directory()->query()->with('resellerAccount:id,user_id');
    }

    public static function getWidgets(): array
    {
        return [UserResource\Widgets\GlobalCustomerSummary::class];
    }

    /** `main` ⇒ فروشگاه اصلی؛ `reseller:{id}` ⇒ نماینده با نام لاتین (slug) */
    private static function storeLabel(string $scopeKey, ?string $slug = null): string
    {
        if ($scopeKey === GlobalCustomerDirectory::MAIN) {
            return 'فروشگاه اصلی';
        }

        return 'نماینده: '.($slug ?: '#'.substr($scopeKey, 9));
    }

    /** @return array<string, string> */
    private static function storeOptions(): array
    {
        return ['main' => 'فروشگاه اصلی']
            + Reseller::query()->orderBy('slug')->pluck('slug', 'id')
                ->mapWithKeys(fn ($slug, $id) => ['reseller:'.$id => 'نماینده: '.($slug ?: '#'.$id)])->all();
    }

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'کاربران';

    protected static ?string $navigationLabel = 'کاربران';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('full_name')->label('نام')->maxLength(255),
            Forms\Components\TextInput::make('telegram_id')->label('شناسه تلگرام')->disabled(),
            Forms\Components\TextInput::make('phone')->label('شماره موبایل'),
            Forms\Components\Select::make('status')
                ->label('وضعیت')
                ->options(self::USER_STATUS_LABELS)
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('نام')
                    ->default('—')
                    ->searchable(['full_name', 'email', 'phone', 'telegram_id', 'username_site'])
                    ->description(fn (User $record) => collect([$record->email, $record->phone, $record->telegram_id ? 'تلگرام: '.$record->telegram_id : null])->filter()->first()),
                Tables\Columns\TextColumn::make('role')
                    ->label('نقش')
                    ->badge()
                    ->getStateUsing(fn (User $record) => match (true) {
                        $record->resellerAccount !== null => 'reseller',
                        $record->isBotAdmin() => 'admin',
                        default => 'customer',
                    })
                    ->colors(['success' => 'reseller', 'warning' => 'admin', 'gray' => 'customer'])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'reseller' => '🏬 نماینده', 'admin' => '🔑 ادمین ربات', default => 'مشتری',
                    }),
                Tables\Columns\TextColumn::make('identities')
                    ->label('هویت متصل')
                    ->badge()
                    ->getStateUsing(fn (User $record) => array_values(array_filter([
                        (int) $record->google_identities_count > 0 ? 'Google' : null,
                        $record->telegram_id ? 'تلگرام' : null,
                        $record->email ? ($record->email_verified_at ? 'ایمیل ✓' : 'ایمیل ✗') : null,
                    ])))
                    ->placeholder('—')
                    ->color(fn (string $state) => match ($state) {
                        'Google', 'ایمیل ✓' => 'success',
                        'تلگرام' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stores_count')
                    ->label('فروشگاه‌ها')
                    ->numeric()
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
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('main_balance')
                    ->label('موجودی Main')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->description(fn (User $record) => (int) $record->stores_balance !== 0 ? 'نمایندگی‌ها: '.Money::format((int) $record->stores_balance) : null)
                    ->sortable(),
                Tables\Columns\TextColumn::make('settled_orders_count')
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
                Tables\Columns\TextColumn::make('open_tickets_count')
                    ->label('تیکت باز')
                    ->numeric()
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : null)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('joined_from')
                    ->label('منبع عضویت')
                    ->formatStateUsing(fn ($state) => self::JOINED_FROM_LABELS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors(['success' => 'active', 'warning' => 'disabled', 'danger' => 'blocked'])
                    ->formatStateUsing(fn ($state) => self::USER_STATUS_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ عضویت')
                    ->formatStateUsing(fn ($state) => $state ? JalaliDate::format(CarbonImmutable::parse($state)) : null)
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options(self::USER_STATUS_LABELS),

                Tables\Filters\SelectFilter::make('role')
                    ->label('نقش')
                    ->options(['reseller' => '🏬 نماینده', 'admin' => '🔑 ادمین ربات', 'customer' => 'مشتری'])
                    ->query(function (Builder $query, array $data) {
                        $adminIds = array_map('intval', config('telegram.admin_ids', []));

                        if ($data['value'] === 'reseller') {
                            $query->whereHas('resellerAccount');
                        } elseif ($data['value'] === 'admin') {
                            $query->whereIn('telegram_id', $adminIds)->whereDoesntHave('resellerAccount');
                        } elseif ($data['value'] === 'customer') {
                            $query->whereNotIn('telegram_id', $adminIds)->whereDoesntHave('resellerAccount');
                        }
                    }),

                Tables\Filters\SelectFilter::make('segment')
                    ->label('بخش مشتریان')
                    ->options(GlobalCustomerSegment::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $segment = GlobalCustomerSegment::fromInput($data['value'] ?? null);

                        return $segment ? self::directory()->applySegment($query, $segment) : $query;
                    }),

                Tables\Filters\SelectFilter::make('store')
                    ->label('عضو فروشگاه')
                    ->options(fn () => self::storeOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::directory()->applyStore($query, $data['value'] ?? null)),

                Tables\Filters\SelectFilter::make('identity')
                    ->label('هویت متصل')
                    ->options(GlobalCustomerIdentity::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $identity = GlobalCustomerIdentity::fromInput($data['value'] ?? null);

                        return $identity ? self::directory()->applyIdentity($query, $identity) : $query;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('adjustBalance')
                    ->label('💰 تنظیم موجودی')
                    ->color('warning')
                    ->form([
                        MoneyInput::make('amount')
                            ->label('مبلغ (مثبت برای افزایش، منفی برای کاهش)')
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('توضیح (برای گردش حساب)')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->adminAdjust(
                                $record,
                                (int) $data['amount'],
                                description: $data['description']
                            );

                            Notification::make()->title('موجودی با موفقیت به‌روزرسانی شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا: '.$e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading('کاربری با این مشخصات پیدا نشد')
            ->emptyStateDescription('کاربران از ربات یا وب‌سایت (مستقیم یا نمایندگی) ساخته می‌شوند. فیلترها را بردارید.');
    }

    /**
     * پرونده‌ی سراسری مشتری (B7.2): هویت، همه‌ی فروشگاه‌ها (جدا از هم)، سرویس‌ها، سفارش‌ها، پرداخت‌ها و تیکت‌ها.
     * علت داخلی شکست (failure_reason)، config_data و لینک اشتراک سرویس نمایش داده نمی‌شود.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $record = $infolist->getRecord();

        abort_unless($record instanceof User, 404);

        $p = self::directory()->profile($record);
        $u = $p->user;

        $stores = $p->stores->map(fn (CustomerStoreRow $r) => [
            'store' => self::storeLabel($r->scopeKey, $r->resellerSlug),
            'membership' => match ($r->membershipStatus) {
                null => 'بدون عضویت',
                'removed' => 'عضویت حذف‌شده',
                default => self::USER_STATUS_LABELS[$r->membershipStatus] ?? $r->membershipStatus,
            },
            'joined' => $r->joinedAt ? JalaliDate::format($r->joinedAt) : '—',
            'wallet' => Money::format($r->walletBalance),
            'orders' => number_format($r->orders),
            'spent' => Money::format($r->spent),
            'platform' => Money::format($r->platformRevenue),
            'services' => number_format($r->activeServices),
            'last' => $r->lastOrderAt ? JalaliDate::format($r->lastOrderAt) : '—',
        ])->all();

        // لینک پیمایشی (Channel): برچسب فروشگاه ← ویرایش نماینده؛ شناسه‌ی سفارش/پرداخت/تیکت ← صفحه‌ی همان رکورد
        $storeUrls = $p->stores
            ->filter(fn (CustomerStoreRow $r) => $r->resellerId !== null)
            ->mapWithKeys(fn (CustomerStoreRow $r) => [
                self::storeLabel($r->scopeKey, $r->resellerSlug) => ResellerResource::getUrl('edit', ['record' => $r->resellerId]),
            ])->all();
        $recordUrl = fn (string $resource, string $page) => fn (?string $state): ?string => $state !== null && ctype_digit(ltrim($state, '#'))
            ? $resource::getUrl($page, ['record' => (int) ltrim($state, '#')])
            : null;
        $storeUrl = fn (?string $state): ?string => $storeUrls[$state] ?? null;

        $services = $p->services->map(fn (Account $a) => [
            'product' => $a->product?->name ?? '—',
            'store' => self::storeLabel($p->serviceStoreKeys[$a->id] ?? 'main', self::slugOf($p, $p->serviceStoreKeys[$a->id] ?? 'main')),
            'state' => self::SERVICE_STATE_LABELS[$a->displayState()] ?? $a->displayState(),
            'expires' => $a->expires_at ? JalaliDate::format($a->expires_at).' ('.$a->remainingDays().' روز مانده)' : 'بدون انقضا',
            'traffic' => $a->traffic_gb === null
                ? 'نامحدود'
                : rtrim(rtrim(number_format($a->usedTrafficGb(), 2), '0'), '.').' از '.rtrim(rtrim(number_format((float) $a->traffic_gb, 2), '0'), '.').' گیگابایت',
        ])->all();

        $orders = $p->recentOrders->map(fn (Order $o) => [
            'id' => '#'.$o->id,
            'store' => self::storeLabel($o->reseller_id ? 'reseller:'.$o->reseller_id : 'main', $o->reseller?->slug),
            'product' => $o->product?->name ?? '—',
            'kind' => $o->isRenewal() ? 'تمدید' : 'خرید',
            'price' => Money::format((int) ($o->main_price ?? $o->customers_price ?? 0)),
            'status' => Order::statusLabels()[$o->status] ?? $o->status,
            'date' => JalaliDate::format($o->created_at),
        ])->all();

        $payments = $p->recentPayments->map(fn (Payment $pay) => [
            'id' => '#'.$pay->id,
            'store' => self::storeLabel($pay->reseller_id ? 'reseller:'.$pay->reseller_id : 'main', $pay->reseller?->slug),
            'purpose' => self::PAYMENT_PURPOSE_LABELS[$pay->purpose] ?? $pay->purpose,
            'amount' => Money::format((int) $pay->amount),
            'status' => self::PAYMENT_STATUS_LABELS[$pay->status] ?? $pay->status,
            'date' => JalaliDate::format($pay->created_at),
        ])->all();

        $tickets = $p->recentTickets->map(fn (Ticket $t) => [
            'id' => '#'.$t->id,
            'subject' => $t->subject,
            'store' => self::storeLabel($t->reseller_id ? 'reseller:'.$t->reseller_id : 'main', $t->reseller?->slug),
            'status' => self::TICKET_STATUS_LABELS[$t->status] ?? $t->status,
            'date' => JalaliDate::format($t->created_at),
        ])->all();

        $walletTx = $p->recentWalletTransactions->map(fn (WalletTransaction $t) => [
            'id' => '#'.$t->id,
            'store' => self::storeLabel((string) $t->wallet?->scope_key, self::slugOf($p, (string) $t->wallet?->scope_key)),
            'type' => WalletTransaction::typeLabels()[$t->type] ?? $t->type,
            'amount' => ($t->amount > 0 ? '+' : '').Money::format((int) $t->amount),
            'after' => Money::format((int) $t->balance_after),
            'note' => $t->publicDescription() ?: '—',
            'date' => JalaliDate::format($t->created_at),
        ])->all();

        $empty = fn (string $key, string $text, bool $visible) => TextEntry::make($key)->label('')->state($text)->visible($visible);

        return $infolist->schema([
            Section::make('هویت')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('p_name')->label('نام')->state($u->full_name ?: '—'),
                    TextEntry::make('p_status')->label('وضعیت کاربر')->badge()
                        ->state(self::USER_STATUS_LABELS[$u->status] ?? $u->status)
                        ->color(match ($u->status) {
                            'active' => 'success', 'blocked' => 'danger', default => 'warning'
                        }),
                    TextEntry::make('p_joined')->label('عضویت از')
                        ->state(($u->created_at ? JalaliDate::format($u->created_at) : '—').' · '.(self::JOINED_FROM_LABELS[$u->joined_from] ?? $u->joined_from)),
                    TextEntry::make('p_email')->label('ایمیل')
                        ->state($u->email ? $u->email.($u->email_verified_at ? ' ✓ تأییدشده' : ' ✗ تأییدنشده') : '—'),
                    TextEntry::make('p_phone')->label('موبایل')->state($u->phone ?: '—'),
                    TextEntry::make('p_username')->label('نام کاربری سایت')->state($u->username_site ?: '—'),
                    TextEntry::make('p_telegram')->label('شناسه تلگرام')->state($u->telegram_id ?: '—'),
                    TextEntry::make('p_google')->label('Google')
                        ->state($p->google ? ($p->google->provider_email ?: 'متصل').($p->google->last_login_at ? ' · آخرین ورود '.JalaliDate::format($p->google->last_login_at) : '') : 'متصل نیست'),
                    TextEntry::make('p_unified')->label('هویت یکپارچه (Google + تلگرام)')->badge()
                        ->state($p->hasUnifiedIdentity() ? 'برقرار' : 'ناقص')
                        ->color($p->hasUnifiedIdentity() ? 'success' : 'gray'),
                    TextEntry::make('p_referrer')->label('معرف')
                        ->url($p->referrer ? self::getUrl('view', ['record' => $p->referrer->id]) : null)
                        ->state($p->referrer ? ($p->referrer->full_name ?: $p->referrer->email ?: 'کاربر #'.$p->referrer->id).' (#'.$p->referrer->id.')' : '—'),
                    TextEntry::make('p_referred')->label('معرفی‌شده‌ها')->state(number_format($p->referredCount)),
                    TextEntry::make('p_reseller')->label('نمایندگی')
                        ->url($p->ownedReseller ? ResellerResource::getUrl('edit', ['record' => $p->ownedReseller->id]) : null)
                        ->state($p->ownedReseller ? 'صاحب نمایندگی '.($p->ownedReseller->slug ?: '#'.$p->ownedReseller->id).($p->ownedReseller->isActive() ? '' : ' (غیرفعال)') : '—'),
                ]),
            ]),
            Section::make('خلاصه‌ی فعالیت (همه‌ی فروشگاه‌ها)')
                ->description('هر فروشگاه کیف‌پول و سابقه‌ی جدا دارد و Merge نمی‌شود؛ جمع‌ها فقط نمایشی‌اند و موجودی در فروشگاه دیگر قابل‌خرج نیست.')
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('p_orders')->label('سفارش‌ها')->state(number_format($p->orders).' ('.number_format($p->renewals).' تمدید)'),
                        TextEntry::make('p_spent')->label('مجموع خرید')->state(Money::format($p->spent)),
                        TextEntry::make('p_platform')->label('درآمد پلتفرم از این مشتری')->state(Money::format($p->platformRevenue)),
                        TextEntry::make('p_last')->label('آخرین خرید')->state($p->lastOrderAt ? JalaliDate::format($p->lastOrderAt) : '—'),
                        TextEntry::make('p_wallet')->label('جمع موجودی کیف‌پول‌ها')->state(Money::format($p->totalWalletBalance())),
                        TextEntry::make('p_active')->label('سرویس فعال')->state(number_format($p->activeServices)),
                        TextEntry::make('p_lapsed')->label('سرویس تمام‌شده')->state(number_format($p->lapsedServices)),
                        TextEntry::make('p_tickets')->label('تیکت باز')->state(number_format($p->openTickets)),
                    ]),
                ]),
            Section::make('فروشگاه‌ها')->schema([
                RepeatableEntry::make('p_stores')->label('')->state($stores)->grid(2)->schema([
                    TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                    TextEntry::make('membership')->label('عضویت')->badge(),
                    TextEntry::make('joined')->label('عضویت از'),
                    TextEntry::make('wallet')->label('موجودی کیف‌پول'),
                    TextEntry::make('orders')->label('سفارش‌ها'),
                    TextEntry::make('spent')->label('مجموع خرید'),
                    TextEntry::make('platform')->label('درآمد پلتفرم'),
                    TextEntry::make('services')->label('سرویس فعال'),
                    TextEntry::make('last')->label('آخرین خرید'),
                ])->hidden($stores === []),
            ]),
            Section::make('سرویس‌ها')->schema([
                RepeatableEntry::make('p_services')->label('')->state($services)->grid(2)->schema([
                    TextEntry::make('product')->label('محصول'),
                    TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                    TextEntry::make('state')->label('وضعیت')->badge(),
                    TextEntry::make('expires')->label('انقضا'),
                    TextEntry::make('traffic')->label('مصرف'),
                ])->hidden($services === []),
                $empty('p_no_services', 'این کاربر هنوز سرویسی ندارد.', $services === []),
            ])->collapsible(),
            Section::make('آخرین سفارش‌ها')->schema([
                RepeatableEntry::make('p_orders_list')->label('')->state($orders)->grid(2)->schema([
                    TextEntry::make('id')->label('سفارش')->url($recordUrl(OrderResource::class, 'view')),
                    TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                    TextEntry::make('product')->label('محصول'),
                    TextEntry::make('kind')->label('نوع'),
                    TextEntry::make('price')->label('مبلغ'),
                    TextEntry::make('status')->label('وضعیت')->badge(),
                    TextEntry::make('date')->label('تاریخ'),
                ])->hidden($orders === []),
                $empty('p_no_orders', 'سفارشی ثبت نشده است.', $orders === []),
            ])->collapsible(),
            Section::make('آخرین پرداخت‌ها')->schema([
                RepeatableEntry::make('p_payments_list')->label('')->state($payments)->grid(2)->schema([
                    TextEntry::make('id')->label('پرداخت')->url($recordUrl(PaymentResource::class, 'view')),
                    TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                    TextEntry::make('purpose')->label('بابت'),
                    TextEntry::make('amount')->label('مبلغ'),
                    TextEntry::make('status')->label('وضعیت')->badge(),
                    TextEntry::make('date')->label('تاریخ'),
                ])->hidden($payments === []),
                $empty('p_no_payments', 'پرداختی ثبت نشده است.', $payments === []),
            ])->collapsed(),
            Section::make('آخرین تراکنش‌های کیف‌پول')
                ->description('به‌تفکیک فروشگاه؛ توضیحِ پاداش معرفی عمداً نمایش داده نمی‌شود (نام شخص دیگری را دارد).')
                ->schema([
                    RepeatableEntry::make('p_wallet_list')->label('')->state($walletTx)->grid(2)->schema([
                        TextEntry::make('id')->label('تراکنش'),
                        TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                        TextEntry::make('type')->label('نوع')->badge(),
                        TextEntry::make('amount')->label('مبلغ'),
                        TextEntry::make('after')->label('موجودی پس از تراکنش'),
                        TextEntry::make('note')->label('توضیح'),
                        TextEntry::make('date')->label('تاریخ'),
                    ])->hidden($walletTx === []),
                    $empty('p_no_wallet', 'تراکنشی در کیف‌پول‌ها ثبت نشده است.', $walletTx === []),
                ])->collapsed(),
            Section::make('تیکت‌ها')->schema([
                RepeatableEntry::make('p_tickets_list')->label('')->state($tickets)->grid(2)->schema([
                    TextEntry::make('id')->label('تیکت')->url($recordUrl(TicketResource::class, 'view')),
                    TextEntry::make('subject')->label('موضوع'),
                    TextEntry::make('store')->label('فروشگاه')->url($storeUrl),
                    TextEntry::make('status')->label('وضعیت')->badge(),
                    TextEntry::make('date')->label('تاریخ'),
                ])->hidden($tickets === []),
                $empty('p_no_tickets', 'تیکتی ثبت نشده است.', $tickets === []),
            ])->collapsed(),
        ]);
    }

    private static function slugOf(GlobalCustomerProfile $profile, string $scopeKey): ?string
    {
        return $profile->stores->firstWhere('scopeKey', $scopeKey)?->resellerSlug;
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
        return $state && CarbonImmutable::parse($state)->lte(now()->addDays(GlobalCustomerDirectory::EXPIRING_DAYS));
    }

    // ---- جستجوی سراسری (B1.3) ----
    protected static ?string $recordTitleAttribute = 'full_name';

    /** جستجوی سراسری فقط به رکورد کاربر نیاز دارد؛ ستون‌های محاسبه‌شده‌ی فهرست را اجرا نمی‌کند */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return User::query();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['full_name', 'email', 'phone', 'telegram_id', 'username_site'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->full_name ?: ($record->email ?: 'کاربر #'.$record->id);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'ایمیل' => $record->email,
            'موبایل' => $record->phone,
            'تلگرام' => $record->telegram_id,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        // کاربران فقط از طریق ربات/سایت ساخته می‌شوند، نه دستی از پنل.
        return false;
    }
}
