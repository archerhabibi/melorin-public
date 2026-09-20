<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\ResolvesCurrentReseller;
use App\Jobs\SendResellerBroadcastMessage;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * پیام همگانی نماینده (درخواست صریح: «پنل نمایندگان هم نیاز به پیام
 * همگانی دارد»). گیرنده‌ها فقط مشتریان همین نماینده‌اند و ارسال با
 * ربات خودِ نماینده انجام می‌شود — ر.ک. توضیحات
 * App\Jobs\SendResellerBroadcastMessage.
 */
class BroadcastMessage extends Page implements HasForms
{
    use InteractsWithForms;
    use ResolvesCurrentReseller;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'پیام همگانی';

    protected static ?string $title = 'پیام همگانی به مشتریان من';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.reseller.pages.broadcast-message';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('text')
                    ->label('متن پیام همگانی')
                    ->helperText('این متن عیناً برای همه‌ی مشتریان شما که در ربات شما عضو هستند فرستاده می‌شود.')
                    ->rows(8)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $reseller = static::currentReseller();
        $data = $this->form->getState();

        if (! $reseller->bot_token) {
            Notification::make()
                ->title('ربات شما هنوز تنظیم نشده است.')
                ->body('تا وقتی توکن ربات شما ثبت نشده باشد، امکان ارسال پیام همگانی وجود ندارد.')
                ->warning()
                ->send();

            return;
        }

        // همان منبعِ حقیقتِ ارسال واقعی (عضویت فعال در فروشگاه این نماینده)
        $recipientCount = app(\App\Services\Core\BroadcastService::class)->recipientCount($reseller);

        if ($recipientCount === 0) {
            Notification::make()
                ->title('هنوز هیچ مشتری‌ای برای ارسال ندارید.')
                ->warning()
                ->send();

            return;
        }

        SendResellerBroadcastMessage::dispatch($reseller, $data['text']);

        $this->form->fill();

        Notification::make()
            ->title("ارسال به {$recipientCount} مشتری آغاز شد.")
            ->body('ارسال در پس‌زمینه انجام می‌شود و ممکن است چند دقیقه طول بکشد.')
            ->success()
            ->send();
    }
}
