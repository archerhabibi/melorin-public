<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\TestAccountSetting;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیمات اکانت تست (رفع بند مربوطه در گزارش Audit فاز A). یک صفحه‌ی
 * تک‌فرمی singleton — دقیقاً هم‌الگو با این‌که AffiliateSetting هم
 * تک‌رکوردی است، فقط اینجا برخلاف AffiliateSetting یک UI هم دارد چون
 * بدون آن ادمین راهی برای فعال/تنظیم کردنش ندارد.
 */
class TestAccountSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'اکانت تست';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.test-account-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            TestAccountSetting::current()->only(['enabled', 'product_id', 'max_per_user', 'traffic_mb', 'duration_hours'])
        );
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('enabled')
                    ->label('اکانت تست فعال باشد')
                    ->helperText('طبق سیاست انتشار، تا وقتی این گزینه فعال نباشد یا محصولی انتخاب نشده باشد، دکمه‌ی «دریافت اکانت تست» اصلاً در منوی ربات نشان داده نمی‌شود.'),

                Forms\Components\Select::make('product_id')
                    ->label('محصول اکانت تست')
                    ->helperText('این محصول فقط برای تعیین دسته‌بندی/پروتکل و سرورهای مجاز اکانت تست استفاده می‌شود — حجم و مدت اعتبار خودِ محصول نادیده گرفته می‌شود و از دو فیلد زیر خوانده می‌شود. می‌توانید همان محصول با قیمت '.Money::format(0).' را در یک دسته‌بندی جدا و غیرفعال/مخفی از منوی خرید عادی قرار دهید.')
                    ->options(fn () => Product::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(fn (Forms\Get $get) => (bool) $get('enabled')),

                Forms\Components\TextInput::make('traffic_mb')
                    ->label('حجم اکانت تست (مگابایت)')
                    ->helperText('مثلاً ۵۰۰ برای نیم گیگابایت، یا ۱۰۲۴ برای یک گیگابایت.')
                    ->numeric()
                    ->minValue(1)
                    ->default(500)
                    ->suffix('MB')
                    ->required(),

                Forms\Components\TextInput::make('duration_hours')
                    ->label('مدت اعتبار اکانت تست (ساعت)')
                    ->helperText('مثلاً ۱ برای یک ساعت، ۲۴ برای یک روز.')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->suffix('ساعت')
                    ->required(),

                Forms\Components\TextInput::make('max_per_user')
                    ->label('حداکثر تعداد اکانت تست به‌ازای هر کاربر')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        TestAccountSetting::current()->update($data);

        Notification::make()
            ->title('تنظیمات اکانت تست ذخیره شد')
            ->success()
            ->send();
    }
}
