<?php

namespace App\Filament\Pages;

use App\Models\BotContentSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * قوانین خرید و آموزش (بند ۳.۱ سند نیازمندی) — قبلاً این متن در
 * MiscHandler::rules() هاردکد بود و هیچ‌جای پنل وب قابل‌تغییر نبود
 * (طبق درخواست صریح). الگوی این صفحه دقیقاً هم‌شکل با ReferralSettings
 * و TestAccountSettings است.
 */
class PurchaseRulesSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = 'قوانین خرید و آموزش';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.purchase-rules-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'purchase_rules_text' => BotContentSetting::current()->purchaseRulesText(),
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('purchase_rules_text')
                    ->label('متن «📜 قوانین خرید و آموزش»')
                    ->helperText('همین متن، عیناً، وقتی کاربر در ربات روی دکمه‌ی «📜 قوانین خرید و آموزش» بزند فرستاده می‌شود.')
                    ->rows(10)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        BotContentSetting::current()->update($data);

        Notification::make()
            ->title('متن قوانین ذخیره شد')
            ->success()
            ->send();
    }
}
