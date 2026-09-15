<?php

namespace App\Jobs;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * پیام همگانی نماینده (درخواست صریح: «پنل نمایندگان هم نیاز به پیام
 * همگانی دارد»).
 *
 * دو تفاوت بنیادی با SendBroadcastMessage (پیام همگانی Core که با
 * ربات اصلی می‌فرستد):
 *
 * ۱) گیرنده‌ها فقط مشتریانِ همین نماینده‌اند (users.reseller_id)، نه
 *    همه‌ی کاربران پلتفرم. این همان «اصل طلایی جداسازی داده» (بند ۲
 *    سند نیازمندی Reseller) است — یک نماینده هرگز نباید بتواند به
 *    مشتریان نماینده‌ی دیگر یا مشتریان مستقیم Core پیام بدهد.
 *
 * ۲) ارسال با bot_token خودِ نماینده انجام می‌شود (از طریق
 *    ResellerApiFactory)، نه ربات اصلی — چون مشتریِ نماینده اصلاً در
 *    ربات اصلی عضو نیست و chat_id او فقط در ربات همان نماینده معتبر
 *    است. استفاده از Api پیش‌فرض اینجا باعث می‌شد همه‌ی ارسال‌ها با
 *    خطای chat not found شکست بخورند.
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

    public function handle(ResellerApiFactory $apiFactory): void
    {
        if (! $this->reseller->bot_token) {
            Log::warning('پیام همگانی نماینده لغو شد: bot_token تنظیم نشده است.', [
                'reseller_id' => $this->reseller->id,
            ]);

            return;
        }

        $telegram = $apiFactory->make($this->reseller);

        $sent = 0;
        $failed = 0;

        User::query()
            ->where('reseller_id', $this->reseller->id)
            ->whereNotNull('telegram_id')
            ->select(['id', 'telegram_id'])
            ->cursor()
            ->each(function (User $user) use ($telegram, &$sent, &$failed) {
                try {
                    $telegram->sendMessage([
                        'chat_id' => $user->telegram_id,
                        'text' => $this->text,
                    ]);
                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('ارسال پیام همگانی نماینده به یک کاربر ناموفق بود.', [
                        'reseller_id' => $this->reseller->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // ~۲۵ پیام در ثانیه — زیر سقف نرخِ تلگرام.
                usleep(40000);
            });

        Log::info("پیام همگانی نماینده #{$this->reseller->id} ارسال شد: {$sent} موفق، {$failed} ناموفق.");
    }
}
