<?php

namespace App\Jobs;

use App\Services\Core\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Telegram\Bot\Api;

/**
 * پیام همگانی ادمین اصلی (بند ۲۲ سند: «ارسال پیام» از پنل مدیریت).
 * عمداً روی صف اجرا می‌شود — برای تعداد زیاد کاربر، ارسال synchronous
 * می‌تواند دقیقه‌ها طول بکشد و درخواست HTTP ادمین را timeout بزند.
 * Supervisor از قبل یک queue worker اجرا می‌کند (melorin-worker).
 *
 * از v3.0.9 منطق ارسال به BroadcastService منتقل شده تا:
 *   - نتیجه‌ی تک‌تک گیرنده‌ها ثبت و در پنل قابل‌مشاهده باشد،
 *   - هر گیرنده در صورت خطای گذرا تا سه بار retry شود،
 *   - در پاسخ ۴۲۹ تلگرام، دقیقاً به اندازه‌ی retry_after صبر شود.
 */
class SendBroadcastMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $text) {}

    public function handle(Api $telegram, ?BroadcastService $broadcasts = null): void
    {
        $broadcasts ??= app(BroadcastService::class);

        $broadcast = $broadcasts->create($this->text, null);

        $broadcasts->dispatchPending($broadcast, $telegram);
    }
}
