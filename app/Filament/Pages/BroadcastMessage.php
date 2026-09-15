<?php

namespace App\Filament\Pages;

use App\Jobs\SendBroadcastMessage;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * پیام همگانی (بند ۲۲ سند: «ارسال پیام» از پنل مدیریت) — پیامی که
 * ادمین اینجا بنویسد، برای همه‌ی کاربرانی که telegram_id دارند در
 * ربات ارسال می‌شود. ارسال واقعی روی صف انجام می‌شود
 * (App\Jobs\SendBroadcastMessage) تا برای تعداد زیاد کاربر این صفحه
 * timeout نخورد؛ شمارش گیرنده‌ها (COUNT ساده) همین‌جا synchronous
 * انجام می‌شود چون سریع است.
 */
class BroadcastMessage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'پیام‌رسانی';

    protected static ?string $navigationLabel = 'پیام همگانی';

    protected static string $view = 'filament.pages.broadcast-message';

    public ?array $data = [];

    /**
     * رفع باگ گزارش‌شده («پیام همگانی درست کار نمی‌کند»): بدون این
     * mount()، فرم هیچ‌وقت مقداردهی اولیه نمی‌شد. در Filament v3 یک
     * Page ای که از InteractsWithForms استفاده می‌کند، برخلاف
     * Resourceها، این کار را خودکار انجام نمی‌دهد؛ نتیجه‌اش این بود که
     * $data خالی می‌ماند و getState() در send() با خطا/حالت ناقص مواجه
     * می‌شد — یعنی دکمه‌ی ارسال ظاهراً بی‌اثر به‌نظر می‌رسید.
     */
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
                    ->helperText('این متن، عیناً، برای همه‌ی کاربرانی که شناسه‌ی تلگرام دارند فرستاده می‌شود.')
                    ->rows(8)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $data = $this->form->getState();

        $recipientCount = User::query()->whereNotNull('telegram_id')->count();

        if ($recipientCount === 0) {
            Notification::make()
                ->title('هیچ کاربری با شناسه‌ی تلگرام برای ارسال پیدا نشد.')
                ->warning()
                ->send();

            return;
        }

        SendBroadcastMessage::dispatch($data['text']);

        $this->form->fill();

        Notification::make()
            ->title("ارسال به {$recipientCount} کاربر آغاز شد.")
            ->body('ارسال در پس‌زمینه انجام می‌شود و ممکن است چند دقیقه طول بکشد.')
            ->success()
            ->send();
    }
}
