<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\ProvisioningSetting;
use App\Services\Core\Provisioning\ProvisioningService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیم سراسری «وقتی ساخت/تمدید اکانت بعد از کسر پول شکست بخورد، سیستم
 * چه کند؟» (بند ۳۵ تا ۳۷ سند). سه گزینه؛ هر سه هم برای خرید و هم برای
 * تمدید اعمال می‌شوند (ProvisioningFailureHandler):
 *
 *   retry             — تلاش مجدد خودکار؛ هرگز بازگشت وجه خودکار.
 *   refund            — بازگشت فوری و دوطرفه‌ی وجه؛ بدون تلاش مجدد.
 *   retry_then_refund — هر دو: اول تلاش مجدد، و در صورت شکست همه‌ی تلاش‌ها، بازگشت.
 *
 * دکمه‌های دستی «تلاش مجدد / بازگشت وجه» برای هر سفارش، در صفحه‌ی
 * «سفارش‌ها» و مستقل از این تنظیم در دسترس‌اند.
 */
class ProvisioningSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'شکست ساخت اکانت';

    protected static string $view = 'filament.pages.provisioning-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['failure_policy' => ProvisioningSetting::activePolicy()]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        $max = ProvisioningService::MAX_ATTEMPTS;

        return $form
            ->schema([
                Forms\Components\Radio::make('failure_policy')
                    ->label('در صورت شکست ساخت/تمدید اکانت بعد از کسر پول')
                    ->options([
                        ProvisioningSetting::POLICY_RETRY => "تلاش مجدد خودکار (تا {$max} بار) — بدون بازگشت خودکار وجه",
                        ProvisioningSetting::POLICY_REFUND => 'بازگشت فوری وجه — بدون تلاش مجدد',
                        ProvisioningSetting::POLICY_RETRY_THEN_REFUND => "هر دو: اول تلاش مجدد (تا {$max} بار)، سپس بازگشت خودکار وجه",
                    ])
                    ->descriptions([
                        ProvisioningSetting::POLICY_RETRY => 'پول کسرشده می‌ماند و سیستم با فاصله‌ی ۲ و ۴ دقیقه دوباره تلاش می‌کند (بدون کسر مجدد). اگر همه‌ی تلاش‌ها شکست بخورد، سفارش برای رسیدگی شما (تلاش مجدد یا بازگشت دستی) می‌ماند.',
                        ProvisioningSetting::POLICY_REFUND => 'به‌محض اولین شکست، مبلغ مشتری (و در فروش نمایندگی، مبلغ نماینده) خودکار برمی‌گردد و سفارش «بازگشت‌شده» می‌شود.',
                        ProvisioningSetting::POLICY_RETRY_THEN_REFUND => 'ابتدا مثل حالت اول تلاش مجدد خودکار انجام می‌شود؛ اگر پس از آخرین تلاش هم سرویس ساخته نشد، وجه مشتری (و نماینده) خودکار بازگردانده می‌شود. مشتری در هر دو نتیجه در ربات مطلع می‌شود.',
                    ])
                    ->default(ProvisioningSetting::POLICY_RETRY)
                    ->in(ProvisioningSetting::policies())
                    ->required(),

                Forms\Components\Placeholder::make('pending_info')
                    ->label('سفارش‌های نیازمند رسیدگی در همین لحظه')
                    ->content(fn () => Order::query()->where('status', Order::STATUS_PROVISION_FAILED)->count().' سفارش (صفحه‌ی «سفارش‌ها» ← فیلتر وضعیت)')
                    ->helperText('تغییر سیاست فقط روی شکست‌های بعدی اثر دارد؛ سفارش‌های ازپیش‌شکست‌خورده با دکمه‌های همان صفحه رسیدگی می‌شوند.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ProvisioningSetting::current()->update([
            'failure_policy' => $data['failure_policy'],
        ]);

        Notification::make()
            ->title('تنظیمات شکست ساخت اکانت ذخیره شد')
            ->success()
            ->send();
    }
}
