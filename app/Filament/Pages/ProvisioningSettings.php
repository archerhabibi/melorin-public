<?php

namespace App\Filament\Pages;

use App\Models\ProvisioningSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیم سراسری «وقتی ساخت اکانت روی پنل بعد از کسر پول شکست بخورد،
 * سیستم چه کار کند؟» — بند ۲۲ سند معماری Reseller: «مگر اینکه سیستم
 * عمداً وضعیت Pending/Retry برای آن عملیات تعریف کرده باشد.»
 *
 * دو گزینه، هر دو در PurchaseService و RenewalService پیاده‌سازی
 * شده‌اند:
 *
 *   retry  — پول کسرشده باقی می‌ماند، سفارش provision_failed می‌شود و
 *            با retryProvisioning() (بدون کسر دوباره) یا رسیدگی دستی
 *            ادمین جبران می‌شود. مناسب وقتی می‌خواهید قبل از بازگشتِ
 *            قطعی، خودتان وضعیت را ببینید.
 *
 *   refund — بازگشت خودکار و فوری: مشتری و (در فروش نمایندگی) نماینده
 *            هر دو بلافاصله پولشان را پس می‌گیرند و سفارش refunded
 *            می‌شود. مناسب وقتی نمی‌خواهید مشتری منتظر رسیدگی دستی
 *            بماند.
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
        $this->form->fill(
            ProvisioningSetting::current()->only(['failure_policy'])
        );
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Radio::make('failure_policy')
                    ->label('در صورت شکست ساخت اکانت بعد از کسر پول')
                    ->options([
                        ProvisioningSetting::POLICY_RETRY => 'در انتظار رسیدگی بماند (retry دستی/خودکار، بدون کسر دوباره)',
                        ProvisioningSetting::POLICY_REFUND => 'وجه بلافاصله و خودکار بازگردانده شود',
                    ])
                    ->descriptions([
                        ProvisioningSetting::POLICY_RETRY => 'سفارش با وضعیت «نیازمند رسیدگی» ثبت می‌شود. مشتری/نماینده هیچ‌کدام بلافاصله چیزی پس نمی‌گیرند؛ ادمین می‌تواند تلاش مجدد بزند یا دستی بازگشت وجه کند.',
                        ProvisioningSetting::POLICY_REFUND => 'به‌محض شکست، مبلغ مشتری (و در فروش نمایندگی، مبلغ نماینده) خودکار به کیف‌پولشان برمی‌گردد و سفارش بازگشت‌خورده ثبت می‌شود.',
                    ])
                    ->default(ProvisioningSetting::POLICY_RETRY)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ProvisioningSetting::current()->update($data);

        Notification::make()
            ->title('تنظیمات شکست ساخت اکانت ذخیره شد')
            ->success()
            ->send();
    }
}
