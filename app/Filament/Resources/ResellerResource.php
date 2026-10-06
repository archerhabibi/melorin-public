<?php

namespace App\Filament\Resources;

use App\Support\Money;
use App\Filament\Resources\ResellerResource\Pages;
use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Resellers\Domains\ResellerDomainService;
use App\Services\Resellers\ResellerService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * طبق درخواست صریح: «وقتی می‌خواهیم یک کاربر را به درجه‌ی نمایندگی
 * برسانیم، صفحه‌ای باز شود که توکن ربات، نام لاتین (برای آدرس پنل)،
 * ایمیل/رمز ورود را بگیرد و وب‌هوک ربات نماینده را خودکار وصل کند».
 * این Resource دقیقاً همان است — ولی مدیریتش (لیست/غیرفعال‌سازی) هم
 * همین‌جا انجام می‌شود، در پنل ادمین اصلی، نه پنل خودِ نماینده (بند ۱۵
 * سند معماری Reseller: «Main Admin Scope ≠ Reseller Scope»).
 */
class ResellerResource extends Resource
{
    protected static ?string $model = Reseller::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'نمایندگان';

    protected static ?string $navigationLabel = 'نمایندگان';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'نماینده';

    protected static ?string $pluralModelLabel = 'نمایندگان';

    /** پیشوندهایی که هرگز نباید به‌عنوان اسلاگ نماینده پذیرفته شوند — چون با مسیرهای واقعیِ برنامه تداخل می‌کنند */
    protected static array $reservedSlugs = [
        'admin', 'login', 'logout', 'api', 'up', 'storage', 'livewire',
        'sanctum', 'reseller-bot', 'reseller-login', 'reseller-panel', 'telegram',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('user_id')
                ->label('کاربر (owner)')
                ->helperText('این کاربر باید از قبل در ربات/سایت ثبت‌نام کرده باشد.')
                ->options(fn () => User::query()
                    ->whereDoesntHave('resellerAccount')
                    ->get()
                    ->mapWithKeys(fn (User $u) => [$u->id => "{$u->full_name} ({$u->telegram_id})"]))
                ->searchable()
                ->required()
                ->disabledOn('edit'),

            Forms\Components\TextInput::make('bot_token')
                ->label('توکن ربات نماینده (BotFather)')
                ->required()
                ->autocomplete(false)
                ->helperText('این مقدار رمزنگاری‌شده ذخیره می‌شود.'),

            Forms\Components\TextInput::make('slug')
                ->label('نام لاتین (آدرس پنل)')
                ->required()
                ->rule('regex:/^[a-z0-9\-]+$/')
                ->unique(ignoreRecord: true)
                ->validationMessages(['regex' => 'فقط حروف کوچک انگلیسی، عدد و خط تیره مجاز است.'])
                ->rules([
                    fn () => function (string $attribute, $value, \Closure $fail) {
                        if (in_array(mb_strtolower((string) $value), self::$reservedSlugs, true)) {
                            $fail('این نام رزرو شده و قابل استفاده نیست.');
                        }
                    },
                ])
                ->prefix(config('app.url').'/')
                ->helperText('آدرس نهایی پنل نماینده، مثلاً parismobile'),

            Forms\Components\TextInput::make('email')
                ->label('ایمیل ورود به پنل نماینده')
                ->email()
                ->required()
                ->unique('users', 'email', modifyRuleUsing: fn ($rule, $record) => $rule->ignore($record?->user_id)),

            Forms\Components\TextInput::make('password')
                ->label('رمز عبور ورود به پنل نماینده')
                ->password()
                ->revealable()
                ->required(fn (string $context) => $context === 'create')
                ->rule(Password::default())
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('در ویرایش، خالی بگذارید تا رمز قبلی تغییر نکند.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.full_name')->label('نام نماینده'),
                Tables\Columns\TextColumn::make('slug')
                    ->label('آدرس پنل')
                    ->formatStateUsing(fn (Reseller $record) => $record->panelUrl())
                    ->url(fn (Reseller $record) => $record->panelUrl(), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('wallet.balance')->label('اعتبار نماینده')->formatStateUsing(fn ($state) => $state === null ? null : Money::format((int) $state)),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors(['success' => 'active', 'gray' => 'inactive'])
                    ->formatStateUsing(fn ($state) => $state === 'active' ? 'فعال' : 'غیرفعال'),
                Tables\Columns\BadgeColumn::make('webhook_status')
                    ->label('وب‌هوک')
                    ->colors(['success' => 'ok', 'danger' => 'failed', 'gray' => null])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'ok' => '✅ متصل',
                        'failed' => '❌ ناموفق',
                        default => '— ثبت نشده',
                    })
                    ->tooltip(fn (Reseller $record) => $record->webhook_error),
                // B6.1 — فقط نمایش؛ ثبت/تأیید در پنل خودِ نماینده است. لغو: اکشن «لغو دامنه‌ی اختصاصی».
                Tables\Columns\TextColumn::make('custom_domain')
                    ->label('دامنه‌ی اختصاصی')
                    ->state(fn (Reseller $record) => ResellerWebsiteSetting::forReseller($record)?->custom_domain)
                    ->description(fn (Reseller $record) => match (ResellerWebsiteSetting::forReseller($record)?->custom_domain_status) {
                        'verified' => 'تأیید شده',
                        'pending' => 'در انتظار تأیید',
                        default => null,
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ایجاد')->dateTime('Y-m-d'),
            ])
            ->actions([
                Tables\Actions\Action::make('reconnect_webhook')
                    ->label('اتصال مجدد وب‌هوک')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Reseller $record) {
                        $result = app(ResellerService::class)->registerWebhook($record);

                        $result['success']
                            ? Notification::make()->title('وب‌هوک با موفقیت وصل شد.')->success()->send()
                            : Notification::make()->title('اتصال وب‌هوک ناموفق بود: '.$result['description'])->danger()->send();
                    }),

                Tables\Actions\Action::make('toggle_status')
                    ->label(fn (Reseller $record) => $record->isActive() ? 'غیرفعال کردن' : 'فعال کردن')
                    ->color(fn (Reseller $record) => $record->isActive() ? 'gray' : 'success')
                    ->requiresConfirmation()
                    ->action(function (Reseller $record) {
                        $record->isActive()
                            ? app(ResellerService::class)->deactivate($record)
                            : app(ResellerService::class)->activate($record);
                    }),

                Tables\Actions\Action::make('revoke_domain')
                    ->label('لغو دامنه‌ی اختصاصی')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Reseller $record) => ResellerWebsiteSetting::forReseller($record)?->custom_domain !== null)
                    ->requiresConfirmation()
                    ->modalDescription('دامنه از نماینده گرفته می‌شود و فروشگاه فقط روی آدرس پلتفرم می‌ماند (مثلاً برای سوءاستفاده/فیشینگ). این کار در Audit ثبت می‌شود.')
                    ->action(function (Reseller $record) {
                        $admin = auth('admin')->user();

                        if ($admin && app(ResellerDomainService::class)->revoke($record, $admin)) {
                            Notification::make()->title('دامنه‌ی اختصاصی لغو شد.')->success()->send();
                        }
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // ---- جستجوی سراسری (B1.3) ----
    protected static ?string $recordTitleAttribute = 'slug';

    public static function getGloballySearchableAttributes(): array
    {
        return ['slug', 'user.full_name'];
    }

    public static function getGlobalSearchEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('user');
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        return $record->getFilamentName();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResellers::route('/'),
            'create' => Pages\CreateReseller::route('/create'),
            'edit' => Pages\EditReseller::route('/{record}/edit'),
        ];
    }
}
