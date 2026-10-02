<?php

namespace App\Listeners;

use App\Support\Money;
use App\Events\PaymentConfirmed;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * درخواست واقعی: بعد از تایید رسید/واریزی توسط ادمین در پنل Filament،
 * کاربر باید در همان ربات تلگرام مطلع شود — قبلاً هیچ اطلاع‌رسانی‌ای
 * انجام نمی‌شد و کاربر باید خودش می‌آمد و موجودی کیف پول را چک می‌کرد.
 *
 * فقط برای پرداخت‌هایی که از طریق ربات (کاربر telegram_id دارد) ثبت
 * شده‌اند پیام ارسال می‌شود؛ اگر کاربر از کانال دیگری (سایت) بدون
 * telegram_id ثبت‌نام کرده باشد، سکوت می‌کند.
 */
class NotifyUserOfPaymentConfirmation
{
    public function __construct(protected Api $telegram) {}

    public function handle(PaymentConfirmed $event): void
    {
        $payment = $event->payment->fresh(['user']);
        $user = $payment->user;

        if (! $user?->telegram_id) {
            return;
        }

        $amount = Money::format((int) $payment->amount);

        $text = "✅ واریزی شما تایید شد.\n\nمبلغ {$amount} به کیف پول شما اضافه شد.";

        try {
            $this->telegram->sendMessage([
                'chat_id' => $user->telegram_id,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            // اگر کاربر ربات را بلاک کرده یا هر خطای دیگری، نباید کل
            // فرآیند تایید پرداخت (که در PaymentService از قبل commit
            // شده) را خراب کند — فقط لاگ می‌شود.
            Log::warning('ارسال اعلان تایید پرداخت به کاربر ناموفق بود.', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
