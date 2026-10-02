<?php

namespace App\Filament\Pages;

use App\Support\Money;
use App\Filament\Support\MoneyInput;
use App\Models\AffiliateSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیمات دعوت از دوستان — رفع یک باگ گزارش‌شده: لینک دعوت به کاربر
 * وعده‌ی «پاداش X تومان» می‌داد، ولی تا الان هیچ صفحه‌ی ادمینی برای
 * AffiliateSetting وجود نداشت؛ یعنی حتی اگر منطق واریز پیاده می‌شد،
 * ادمین راهی برای تنظیم مبلغش نداشت (فقط از طریق tinker/دیتابیس مستقیم).
 *
 * عمداً فقط فیلد referrer_bonus_amount اینجا نمایش داده می‌شود — طبق
 * تصمیم صریح، فعلاً فقط «پاداش عضویت به معرف» پیاده‌سازی شده (نگاه کنید
 * به StartHandler::handle). فیلدهای customer_bonus_amount،
 * commission_percent و commission_validity_days در مدل/دیتابیس باقی
 * می‌مانند (برای فاز بعدی، وقتی Commission واقعاً پیاده شود) ولی عمداً
 * اینجا در UI نمایش داده نمی‌شوند — چون نمایش‌شان دقیقاً همان تله‌ی قبلی
 * را تکرار می‌کند: فیلدی که ادمین می‌تواند پر کند ولی هیچ کدی به آن
 * عمل نمی‌کند.
 */
class ReferralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'دعوت از دوستان';

    protected static string $view = 'filament.pages.referral-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            AffiliateSetting::current()->only(['referrer_bonus_amount'])
        );
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                MoneyInput::make('referrer_bonus_amount')
                    ->label('پاداش عضویت به معرف ('.Money::label().')')
                    ->helperText('با عضویت هر شخص جدید از طریق لینک دعوت یک کاربر، همین مبلغ بلافاصله به کیف پول همان کاربر (معرف) واریز می‌شود. صفر یا خالی = این پاداش غیرفعال است.')
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AffiliateSetting::current()->update($data);

        Notification::make()
            ->title('تنظیمات دعوت از دوستان ذخیره شد')
            ->success()
            ->send();
    }
}
