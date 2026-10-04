<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * قبلاً با ثبت یک تیکت هیچ اطلاع‌رسانی‌ای به هیچ‌کجا نمی‌رفت و تیکت
 * عملاً در جدول tickets گم می‌شد تا کسی به‌صورت دستی/تصادفی پنل را چک
 * کند. این Listener با استفاده از همان لیست TELEGRAM_ADMIN_IDS که برای
 * دستور «ادمین» در UpdateRouter استفاده می‌شود (نه مدل Admin پنل)، به
 * تمام ادمین‌های تلگرام یک پیام کوتاه می‌فرستد که تیکت جدیدی باز شده و
 * پاسخ باید از پنل داده شود — طبق تصمیم معماری که مدیریت واقعی همیشه
 * از طریق پنل وب انجام می‌شود، نه داخل ربات.
 */
class NotifyAdminsOfNewTicket
{
    public function __construct(protected Api $telegram) {}

    public function handle(TicketCreated $event): void
    {
        $adminIds = config('telegram.admin_ids', []);

        if (empty($adminIds)) {
            return;
        }

        $ticket = $event->ticket->fresh(['user']);
        $firstMessage = $ticket->messages()->oldest()->value('message');

        $text = "🎫 تیکت پشتیبانی جدید #{$ticket->id}\n\n"
            ."کاربر: {$ticket->user->full_name} (".($ticket->user->telegram_id ? "شناسه: {$ticket->user->telegram_id}" : (string) $ticket->user->email).")\n"
            .($ticket->reseller_id ? "فروشگاه نماینده: #{$ticket->reseller_id}\n" : '')
            ."موضوع: {$ticket->subject}\n\n"
            .mb_substr((string) $firstMessage, 0, 300)
            ."\n\nپاسخ از طریق پنل مدیریت:\n".config('app.url').'/admin/tickets/'.$ticket->id;

        foreach ($adminIds as $adminId) {
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $adminId,
                    'text' => $text,
                ]);
            } catch (\Throwable $e) {
                // یک ادمین که ربات را بلاک کرده یا chat_id نامعتبر دارد نباید
                // مانع اطلاع‌رسانی به بقیه‌ی ادمین‌ها یا خرابی ثبت تیکت شود.
                Log::warning('ارسال اعلان تیکت جدید به ادمین ناموفق بود.', [
                    'ticket_id' => $ticket->id,
                    'admin_telegram_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
