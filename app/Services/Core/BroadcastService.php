<?php

namespace App\Services\Core;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * BroadcastService — اجرای واقعیِ یک کمپین پیام همگانی (P2 گزارش
 * امنیتی، موارد #20، #21، #22).
 *
 * سه مشکل نسخه‌ی قبل که اینجا حل شده‌اند:
 *
 * ۱) نتیجه هیچ‌جا ذخیره نمی‌شد (فقط Log). حالا هر گیرنده یک ردیف در
 *    broadcast_recipients دارد و شمارش‌ها روی خودِ broadcast به‌روز
 *    می‌شوند — یعنی پنل می‌تواند دقیقاً بگوید چند نفر گرفتند و چه کسی
 *    نگرفت.
 *
 * ۲) هیچ retry ای وجود نداشت ($tries = 1). یک خطای گذرای تلگرام
 *    (429/500/timeout) یعنی آن گیرنده برای همیشه از دست می‌رفت. حالا
 *    هر گیرنده تا سه بار تلاش می‌شود و خطای نهایی‌اش ذخیره می‌ماند تا
 *    بعداً قابل‌retry باشد.
 *
 * ۳) نرخ ارسال ثابت و کور بود (usleep ثابت). حالا اگر تلگرام
 *    ۴۲۹ (Too Many Requests) بدهد، مقدار retry_after خودش رعایت
 *    می‌شود — که تنها راه درستِ برخورد با محدودیت نرخ تلگرام است.
 */
class BroadcastService
{
    /** حداکثر تلاش برای هر گیرنده */
    protected const MAX_ATTEMPTS = 3;

    /** فاصله‌ی پایه بین ارسال‌ها (~۲۵ پیام در ثانیه، زیر سقف تلگرام) */
    protected const BASE_DELAY_MICROSECONDS = 40000;

    /**
     * ساخت رکورد کمپین و صف گیرنده‌ها. ارسال واقعی جداگانه (در Job)
     * انجام می‌شود تا درخواست HTTP پنل بلافاصله برگردد.
     */
    public function create(string $message, ?Reseller $reseller = null): Broadcast
    {
        $broadcast = Broadcast::create([
            'reseller_id' => $reseller?->id,
            'message' => $message,
            'status' => 'queued',
        ]);

        $recipients = $this->recipientQuery($reseller)
            ->select(['id'])
            ->cursor()
            ->map(fn (User $user) => [
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->chunk(500);

        $total = 0;

        foreach ($recipients as $chunk) {
            $rows = $chunk->all();
            BroadcastRecipient::insert($rows);
            $total += count($rows);
        }

        $broadcast->update(['total_recipients' => $total]);

        return $broadcast->fresh();
    }

    /** تعداد مخاطبان مجاز یک کمپین (برای نمایش پیش از ارسال) */
    public function recipientCount(?Reseller $reseller): int
    {
        return $this->recipientQuery($reseller)->count();
    }

    /**
     * گیرنده‌های مجاز. برای نماینده فقط مشتریان خودش — همان «اصل طلایی
     * جداسازی داده»؛ یک نماینده هرگز نباید به مشتری نماینده‌ی دیگر یا
     * کاربر مستقیم Core پیام بدهد.
     */
    protected function recipientQuery(?Reseller $reseller): Builder
    {
        // فاز ۱۵ (Rule 12): مخاطب = عضو فعالِ «همان فروشگاه» (CustomerAccount)،
        // نه users.reseller_id تک‌مقداری. کسی که مشتری چند فروشگاه است، پیام
        // هر فروشگاه را جدا و فقط از همان فروشگاه می‌گیرد.
        $query = User::query()->whereNotNull('telegram_id');

        if ($reseller) {
            return $query->whereHas('customerAccounts', fn (Builder $q) => $q
                ->where('status', 'active')
                ->where('store_type', 'reseller')
                ->where('reseller_id', $reseller->id));
        }

        // Main: عضو فعال Main، یا کاربری که هیچ عضویتی ندارد (پیش‌فرضِ «کاربر مستقیم»).
        // مشتریِ صرفاً نمایندگان (و عضویت Mainِ غیرفعال‌شده) مخاطب Main نیست.
        //
        // «پیش‌فرضِ کاربر مستقیم» عمداً صاحبِ یک نماینده را شامل نمی‌شود:
        // طبق Rule 1 سند، هر نماینده از قبل یک User واقعیِ Main بوده، پس
        // در دنیای واقعی همیشه یک CustomerAccount فعالِ main دارد و از
        // همان مسیر اول همین OR واجد شرایط می‌شود. کسی که هیچ
        // CustomerAccount‌ای ندارد ولی صاحبِ یک نماینده هست، صرفاً یک
        // Identity برای ورود به پنل خودش است (مثلاً در تست‌ها ساخته
        // می‌شود)، نه یک «کاربر مستقیم Main» — نباید با پیام‌های Main
        // مزاحمش شد.
        return $query->where(fn (Builder $q) => $q
            ->whereHas('customerAccounts', fn (Builder $a) => $a->where('status', 'active')->where('store_type', 'main'))
            ->orWhere(fn (Builder $q2) => $q2
                ->whereDoesntHave('customerAccounts')
                ->whereDoesntHave('resellerAccount')));
    }

    /** ارسال همه‌ی گیرنده‌های در انتظارِ یک کمپین */
    public function dispatchPending(Broadcast $broadcast, Api $telegram): void
    {
        $broadcast->update([
            'status' => 'sending',
            'started_at' => $broadcast->started_at ?? now(),
        ]);

        $broadcast->recipients()
            ->where('status', 'pending')
            ->with('user:id,telegram_id')
            ->cursor()
            ->each(fn (BroadcastRecipient $recipient) => $this->sendTo($broadcast, $recipient, $telegram));

        $broadcast->update([
            'status' => $broadcast->fresh()->failed_count > 0 ? 'completed' : 'completed',
            'finished_at' => now(),
        ]);
    }

    protected function sendTo(Broadcast $broadcast, BroadcastRecipient $recipient, Api $telegram): void
    {
        $chatId = $recipient->user?->telegram_id;

        if (! $chatId) {
            $recipient->update(['status' => 'failed', 'error' => 'کاربر شناسه‌ی تلگرام ندارد.']);
            $broadcast->increment('failed_count');

            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $telegram->sendMessage(['chat_id' => $chatId, 'text' => $broadcast->message]);

                $recipient->update([
                    'status' => 'sent',
                    'attempts' => $attempt,
                    'sent_at' => now(),
                    'error' => null,
                ]);
                $broadcast->increment('sent_count');

                usleep(self::BASE_DELAY_MICROSECONDS);

                return;
            } catch (\Throwable $e) {
                $retryAfter = $this->retryAfterSeconds($e);

                // ۴۲۹ یعنی «خیلی سریع می‌فرستی» — این یک شکست واقعی
                // نیست و نباید یکی از سه تلاش حساب شود؛ فقط باید دقیقاً
                // به اندازه‌ای که خود تلگرام گفته صبر کرد.
                if ($retryAfter !== null) {
                    sleep($retryAfter);
                    $attempt--;

                    continue;
                }

                if ($attempt === self::MAX_ATTEMPTS) {
                    $recipient->update([
                        'status' => 'failed',
                        'attempts' => $attempt,
                        'error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                    $broadcast->increment('failed_count');

                    Log::warning('broadcast_recipient_failed', [
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $recipient->user_id,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                // عقب‌نشینی نمایی برای خطاهای گذرا (۵۰۰/timeout)
                sleep(2 ** ($attempt - 1));
            }
        }
    }

    /**
     * اگر خطا از نوع محدودیت نرخ تلگرام باشد، تعداد ثانیه‌ای که باید
     * صبر کرد را برمی‌گرداند؛ در غیر این صورت null.
     */
    protected function retryAfterSeconds(\Throwable $e): ?int
    {
        $message = $e->getMessage();

        if (! str_contains($message, '429') && ! str_contains(mb_strtolower($message), 'too many requests')) {
            return null;
        }

        if (preg_match('/retry[_ ]after[^0-9]*(\d+)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return 5;
    }
}
