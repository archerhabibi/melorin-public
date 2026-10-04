<?php

namespace App\Listeners;

use App\Events\TicketUserReplied;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * هم‌الگو با NotifyAdminsOfNewTicket، ولی برای پیام‌های بعدیِ یک تیکتِ
 * موجود — قبلاً وقتی کاربر بعد از پاسخ ادمین دوباره پیام می‌فرستاد،
 * هیچ ادمینی مطلع نمی‌شد مگر خودشان پنل را چک می‌کردند (مکالمه فقط
 * یک‌طرفه بود: ادمین → کاربر). این Listener همان حلقه را برای مسیر
 * برعکس (کاربر → ادمین) هم می‌بندد.
 */
class NotifyAdminsOfTicketReply
{
    public function __construct(protected Api $telegram) {}

    public function handle(TicketUserReplied $event): void
    {
        $adminIds = config('telegram.admin_ids', []);

        if (empty($adminIds)) {
            return;
        }

        $message = $event->message->fresh(['ticket.user']);
        $ticket = $message->ticket;

        $text = "💬 پیام جدید در تیکت #{$ticket->id}\n\n"
            ."کاربر: {$ticket->user->full_name} (".($ticket->user->telegram_id ? "شناسه: {$ticket->user->telegram_id}" : (string) $ticket->user->email).")\n"
            .($ticket->reseller_id ? "فروشگاه نماینده: #{$ticket->reseller_id}\n" : '')
            ."موضوع: {$ticket->subject}\n\n"
            .mb_substr((string) $message->message, 0, 300)
            ."\n\nپاسخ از طریق پنل مدیریت:\n".config('app.url').'/admin/tickets/'.$ticket->id;

        foreach ($adminIds as $adminId) {
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $adminId,
                    'text' => $text,
                ]);
            } catch (\Throwable $e) {
                Log::warning('ارسال اعلان پیام جدید تیکت به ادمین ناموفق بود.', [
                    'ticket_id' => $ticket->id,
                    'admin_telegram_id' => $adminId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
