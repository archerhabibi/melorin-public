<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerPanelResource\Pages;
use App\Models\ServerPanel;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\SupportsServerStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * مدیریت سرورها و پنل‌ها (بند ۵ سند نیازمندی).
 *
 * نکته‌ی امنیتی: فیلد credentials هرگز در جدول نمایش داده نمی‌شود و در
 * فرم به‌صورت password (مخفی) وارد می‌شود — طبق بند ۵.۱ («اطلاعات حساس
 * اتصال باید به‌صورت امن ذخیره شوند») و کست encrypted در مدل ServerPanel.
 */
class ServerPanelResource extends Resource
{
    protected static ?string $model = ServerPanel::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'زیرساخت';

    protected static ?string $navigationLabel = 'سرورها و پنل‌ها';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'سرور / پنل';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پایه')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('نام پنل')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('panel_type')
                        ->label('نوع پنل')
                        ->options([
                            'pasarguard' => 'پاسارگارد',
                            'sanaei' => 'سنایی (3X-UI)',
                        ])
                        ->required()
                        ->live()
                        ->native(false),

                    Forms\Components\TextInput::make('host')
                        ->label('آدرس پنل')
                        ->required()
                        ->helperText('کامل با http:// یا https:// وارد کنید. اگر پورت یا مسیر پایه (مثل /kharej) دارید، همه را همینجا و در همین رشته وارد کنید — نیازی به تکرار پورت در فیلد پایین نیست. مثال: https://panel.example.com:2053/kharej')
                        ->url()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('port')
                        ->label('پورت (اختیاری)')
                        ->numeric()
                        ->helperText('فقط وقتی پر کنید که پورت را داخل آدرس بالا ننوشته باشید — اگر پورت از قبل داخل آدرس هست (مثل :2053)، این فیلد را خالی بگذارید.'),
                ]),

            Forms\Components\Section::make('احراز هویت')
                ->description('این اطلاعات به‌صورت رمزنگاری‌شده ذخیره می‌شوند.')
                ->schema([
                    Forms\Components\TextInput::make('credentials_username')
                        ->label('نام کاربری پنل')
                        ->visible(fn (Forms\Get $get) => $get('panel_type') !== 'sanaei')
                        ->required(fn (string $context, Forms\Get $get) => $context === 'create' && $get('panel_type') !== 'sanaei')
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ServerPanel $record) {
                            if ($record) {
                                $creds = json_decode($record->credentials ?? '{}', true);
                                $component->state($creds['username'] ?? null);
                            }
                        }),

                    Forms\Components\TextInput::make('credentials_password')
                        ->label('رمز عبور پنل')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('panel_type') !== 'sanaei')
                        ->required(fn (string $context, Forms\Get $get) => $context === 'create' && $get('panel_type') !== 'sanaei')
                        ->dehydrated(false)
                        ->helperText(fn (string $context) => $context === 'edit' ? 'برای تغییر رمز، مقدار جدید وارد کنید؛ خالی بگذارید تا رمز فعلی حفظ شود.' : null),

                    // سنایی (3X-UI) از اواخر ۲۰۲۶ به‌جای یوزر/پس، احراز هویت
                    // با Bearer API Token را هم پشتیبانی می‌کند — این توکن را
                    // از Settings → Security → API Token در خودِ پنل بسازید.
                    Forms\Components\TextInput::make('credentials_api_token')
                        ->label('API Token پنل سنایی')
                        ->password()
                        ->revealable()
                        ->visible(fn (Forms\Get $get) => $get('panel_type') === 'sanaei')
                        ->required(fn (string $context, Forms\Get $get) => $context === 'create' && $get('panel_type') === 'sanaei')
                        ->dehydrated(false)
                        ->helperText('از Settings → Security → API Token داخل پنل سنایی بسازید. این توکن دسترسی کامل ادمین دارد.')
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ServerPanel $record) {
                            if ($record) {
                                $creds = json_decode($record->credentials ?? '{}', true);
                                $component->state($creds['api_token'] ?? null);
                            }
                        }),

                    Forms\Components\TextInput::make('extra_settings_template_username')
                        ->label('یوزرنیم کلاینت نمونه (Template)')
                        ->visible(fn (Forms\Get $get) => $get('panel_type') === 'sanaei')
                        ->required(fn (string $context, Forms\Get $get) => $context === 'create' && $get('panel_type') === 'sanaei')
                        ->dehydrated(false)
                        ->helperText('یک کلاینت را دستی و یک‌بار روی پنل، با پروتکل/inbound درست بسازید و email آن را اینجا وارد کنید. اکانت‌های بعدی دقیقاً با همین تنظیمات (پروتکل، inbound، flow) ساخته می‌شوند — نیازی به وارد کردن inbound_id نیست.')
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ServerPanel $record) {
                            if ($record) {
                                $component->state(($record->extra_settings ?? [])['template_username'] ?? null);
                            }
                        }),

                    // بدون این مقدار، SanaeiDriver در ساخت/تمدید اکانت با خطای
                    // صریح متوقف می‌شود (نمی‌تواند لینک سابسکریپشن بسازد) —
                    // چون در 3X-UI سرویس Subscription روی پورت/مسیر کاملاً
                    // جدایی از پنل مدیریت سرو می‌شود.
                    Forms\Components\TextInput::make('extra_settings_sub_base_url')
                        ->label('آدرس کامل سرویس Subscription (شامل مسیر)')
                        ->visible(fn (Forms\Get $get) => $get('panel_type') === 'sanaei')
                        ->required(fn (string $context, Forms\Get $get) => $context === 'create' && $get('panel_type') === 'sanaei')
                        ->url()
                        ->maxLength(255)
                        ->dehydrated(false)
                        ->helperText('این مقدار عیناً و بدون هیچ تغییری قبل از شناسه‌ی مشترک (subId) قرار می‌گیرد — چیزی مثل «/sub» به‌صورت خودکار اضافه نمی‌شود، پس باید خودتان کامل وارد کنید. مسیرش را از پنل 3X-UI ببینید: Settings → Subscription → Sub Path (و Sub Port). مثال درست اگر Sub Path پنل شما «/sub/» و Sub Port آن 2096 است: https://your-domain:2096/sub')
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, ?ServerPanel $record) {
                            if ($record) {
                                $component->state(($record->extra_settings ?? [])['sub_base_url'] ?? null);
                            }
                        }),
                ]),

            Forms\Components\Section::make('ظرفیت و وضعیت')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('status')
                        ->label('فعال')
                        ->default(true)
                        ->formatStateUsing(fn ($state) => $state === 'active')
                        ->dehydrateStateUsing(fn ($state) => $state ? 'active' : 'inactive'),

                    Forms\Components\TextInput::make('capacity')
                        ->label('ظرفیت (تعداد اکانت)')
                        ->numeric(),

                    Forms\Components\TextInput::make('account_limit_per_user')
                        ->label('محدودیت اکانت هر کاربر')
                        ->numeric(),

                    Forms\Components\Placeholder::make('active_accounts_count')
                        ->label('تعداد اکانت‌های فعال فعلی')
                        ->content(fn (?ServerPanel $record) => $record?->active_accounts_count ?? 0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('panel_type')->label('نوع')->badge(),
                Tables\Columns\TextColumn::make('host')->label('آدرس')->limit(30),
                Tables\Columns\TextColumn::make('active_accounts_count')->label('اکانت فعال')->sortable(),
                Tables\Columns\TextColumn::make('capacity')->label('ظرفیت'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->colors(['success' => 'active', 'danger' => 'inactive']),
                Tables\Columns\BadgeColumn::make('health_status')
                    ->label('سلامت')
                    ->colors(['success' => 'healthy', 'warning' => 'degraded', 'danger' => 'down']),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('panel_type')->options([
                    'pasarguard' => 'پاسارگارد',
                    'sanaei' => 'سنایی',
                ]),
                Tables\Filters\TernaryFilter::make('status')
                    ->trueLabel('فعال')->falseLabel('غیرفعال')
                    ->queries(
                        true: fn ($q) => $q->where('status', 'active'),
                        false: fn ($q) => $q->where('status', 'inactive'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('serverStatus')
                    ->label('وضعیت سرور')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (ServerPanel $record) => "وضعیت سرور: {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن')
                    ->visible(function (ServerPanel $record) {
                        try {
                            return PanelDriverFactory::make($record->panel_type)
                                instanceof SupportsServerStatus;
                        } catch (\Throwable) {
                            return false;
                        }
                    })
                    ->modalContent(function (ServerPanel $record) {
                        try {
                            $driver = PanelDriverFactory::make($record->panel_type);
                            $status = $driver->getServerStatus($record);

                            $rows = '';
                            foreach ($status as $label => $value) {
                                $rows .= '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;">'
                                    .'<div style="font-size:12px;color:#6b7280;">'.e($label).'</div>'
                                    .'<div style="font-size:14px;font-weight:600;">'.e($value).'</div>'
                                    .'</div>';
                            }

                            return new HtmlString(
                                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'.$rows.'</div>'
                            );
                        } catch (\Throwable $e) {
                            return new HtmlString(
                                '<div style="color:#dc2626;font-size:14px;">خطا در دریافت وضعیت سرور: '.e($e->getMessage()).'</div>'
                            );
                        }
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServerPanels::route('/'),
            'create' => Pages\CreateServerPanel::route('/create'),
            'edit' => Pages\EditServerPanel::route('/{record}/edit'),
        ];
    }
}
