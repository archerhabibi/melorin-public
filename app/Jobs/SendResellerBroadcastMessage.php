<?php

namespace App\Jobs;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Models\Reseller;
use App\Services\Core\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * پیام همگانی نماینده — ارسال با bot_token خودِ نماینده (نه ربات
 * اصلی)، چون مشتریِ نماینده اصلاً در ربات اصلی عضو نیست و chat_id او
 * فقط در ربات همان نماینده معتبر است. گیرنده‌ها فقط مشتریانِ همین
 * نماینده‌اند — «اصل طلایی جداسازی داده».
 *
 * از v3.0.9 منطق ارسال/retry/نرخ به BroadcastService منتقل شده و
 * نتیجه‌ی تک‌تک گیرنده‌ها در جدول broadcast_recipients ثبت می‌شود
 * (P2 گزارش امنیتی، موارد #20 تا #22). امضای سازنده عمداً دست‌نخورده
 * مانده تا کد و تست‌های موجود نشکنند.
 */
class SendResellerBroadcastMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly Reseller $reseller,
        public readonly string $text,
    ) {}

    public function handle(ResellerApiFactory $apiFactory, ?BroadcastService $broadcasts = null): void
    {
        if (! $this->reseller->bot_token) {
            Log::warning('پیام همگانی نماینده لغو شد: bot_token تنظیم نشده است.', [
                'reseller_id' => $this->reseller->id,
            ]);

            return;
        }

        $broadcasts ??= app(BroadcastService::class);

        $broadcast = $broadcasts->create($this->text, $this->reseller);

        $broadcasts->dispatchPending($broadcast, $apiFactory->make($this->reseller));
    }
}
