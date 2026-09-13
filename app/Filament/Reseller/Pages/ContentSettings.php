<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Models\ResellerBotSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * بند ۱۱ و ۱۴ سند نیازمندی Reseller Platform: نماینده در پنل خودش
 * قوانین خرید، آموزش اتصال و Support ID را تنظیم می‌کند — این محتوا
 * فقط در ربات همین نماینده نمایش داده می‌شود (ر.ک.
 * ResellerBot\Handlers\StartHandler::rules/support). سوییچ bot_enabled
 * هم همان‌جا enforce می‌شود، نه فقط در UI.
 */
class ContentSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use ResolvesCurrentReseller;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'محتوای ربات';

    protected static string $view = 'filament.reseller.pages.content-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = ResellerBotSetting::forReseller(static::currentReseller());

        $this->form->fill([
            'bot_enabled' => $settings->bot_enabled,
            'support_id' => $settings->support_id,
            'rules' => $settings->rules,
            'connection_guide' => $settings->connection_guide,
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('bot_enabled')
                    ->label('ربات فعال است')
                    ->helperText('در حالت غیرفعال، هیچ فروش یا عملیات مالی جدیدی در ربات انجام نمی‌شود.'),
                Forms\Components\TextInput::make('support_id')
                    ->label('آیدی/شناسه‌ی پشتیبانی')
                    ->placeholder('@your_support_id'),
                Forms\Components\Textarea::make('rules')
                    ->label('قوانین خرید')
                    ->rows(6),
                Forms\Components\Textarea::make('connection_guide')
                    ->label('آموزش اتصال')
                    ->rows(6),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        ResellerBotSetting::forReseller(static::currentReseller())->update($this->form->getState());

        Notification::make()->title('ذخیره شد')->success()->send();
    }
}
