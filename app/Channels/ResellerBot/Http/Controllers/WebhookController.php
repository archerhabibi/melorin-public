<?php

namespace App\Channels\ResellerBot\Http\Controllers;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Channels\ResellerBot\UpdateRouter;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Customer\ProfileCenterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/**
 * نقطه‌ی ورودی همه‌ی ربات‌های نمایندگی — یک کنترلر برای همه‌ی
 * bot_token های ممکن، نه یکی به‌ازای هر نماینده. تفاوت کلیدی نسبت به
 * App\Channels\TelegramBot\Http\Controllers\WebhookController دقیقاً
 * همین چندمستأجری‌بودن است؛ بقیه‌ی محافظت‌ها (idempotency، فیلتر is_bot،
 * استفاده از get() به‌جای getMessage()) عیناً از همان‌جا کپی شده چون
 * همان دلایل مستندشده اینجا هم صدق می‌کند.
 *
 * نکته‌ی مهم معماری: Telegram\Bot\Api::class در کانتینر به‌صورت
 * singleton و به توکن ربات اصلی bind شده (TelegramBotServiceProvider
 * پکیج SDK). چون هر نماینده bot_token مستقل خودش را دارد، نمی‌شود از
 * آن Api مشترک استفاده کرد؛ به‌جایش یک نمونه‌ی Api تازه با توکن همین
 * نماینده ساخته و *فقط برای طول همین درخواست* به‌جای singleton موجود
 * در کانتینر می‌نشیند (app()->instance). چون هر درخواست HTTP یک
 * چرخه‌ی کانتینر مستقل دارد، این جایگزینی هرگز بین دو نماینده‌ی
 * مختلف نشتی/تداخل ایجاد نمی‌کند؛ و چون همه‌ی Handlerهای ResellerBot
 * دقیقاً همان الگوی «constructor-injected Api $telegram» ربات اصلی را
 * دارند، بدون هیچ تغییری خودکار به توکن درست وصل می‌شوند.
 */
class WebhookController
{
    public function __construct(protected ResellerApiFactory $apiFactory) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $reseller = Reseller::query()->where('webhook_slug', $slug)->first();

        if (! $reseller || ! $reseller->bot_token) {
            abort(404);
        }

        // احراز هویت واقعیِ تلگرام (P0 گزارش امنیتی). پیش از این، تنها
        // «محافظ» این مسیر حدس‌نزدنی‌بودن slug بود — که یک راز نیست:
        // در URL دیده می‌شود و هرکس آن را داشت می‌توانست Update جعلی
        // بفرستد و خودش را به‌جای یک مشتری جا بزند. ربات اصلی از قبل
        // همین چک را داشت و فقط این مسیر جا افتاده بود.
        //
        // اگر وب‌هوک نماینده بدون secret ثبت شده باشد، تلگرام هدری
        // نمی‌فرستد و درخواست رد می‌شود؛ راه‌حل: اجرای «ثبت وب‌هوک»
        // (Reconnect webhook) از پنل ادمین.
        // Fail-closed (فاز ۸، S-01): پیش از این، اگر نماینده secret نداشت
        // (`if ($expectedSecret)`) چک کلاً رد می‌شد و هر کسی که slug را
        // می‌دانست Update جعلی می‌فرستاد؛ کامنت قبلی هم خلاف رفتار واقعی بود.
        // اکنون بدون secret ← ۴۰۳؛ راه‌حل: «ثبت وب‌هوک» (Reconnect) از پنل ادمین
        // که secret را می‌سازد (ensureWebhookSecret) و در تلگرام ثبت می‌کند.
        $expectedSecret = (string) $reseller->webhook_secret;
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($expectedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning('درخواست وب‌هوک نماینده بدون secret معتبر رد شد.', [
                'reseller_id' => $reseller->id,
                'secret_configured' => $expectedSecret !== '',
            ]);

            abort(403);
        }

        $telegram = $this->apiFactory->make($reseller);
        app()->instance(Api::class, $telegram);

        $update = new Update($request->all());

        $updateId = $update->get('update_id');

        if ($updateId !== null) {
            $cacheKey = "reseller_bot_update_seen_{$reseller->id}_{$updateId}";

            // Cache::add اتمیک است (add-if-absent): برخلاف الگوی
            // has()+put() که بین دو فراخوانی پنجره‌ی رقابت داشت و دو
            // درخواستِ هم‌زمانِ یک Update می‌توانستند هر دو رد شوند از
            // شرط و دو بار پردازش شوند.
            if (! Cache::add($cacheKey, true, now()->addHours(6))) {
                return response('ok');
            }
        }

        $message = $update->get('message');
        $callbackQuery = $update->get('callback_query');

        $telegramUser = $message ? $message->get('from') : ($callbackQuery ? $callbackQuery->get('from') : null);
        $chat = $message ? $message->getChat() : ($callbackQuery ? $callbackQuery->getMessage()->getChat() : null);

        if (! $telegramUser || ! $chat) {
            return response('ok');
        }

        if ($telegramUser->get('is_bot')) {
            return response('ok');
        }

        $freshName = trim($telegramUser->get('first_name').' '.$telegramUser->get('last_name'));

        Log::info('reseller_bot_update_resolved', [
            'reseller_id' => $reseller->id,
            'update_id' => $updateId,
            'type' => $message ? 'message' : ($callbackQuery ? 'callback_query' : 'unknown'),
            'from_id' => $telegramUser->get('id'),
            'chat_id' => $chat->getId(),
        ]);

        $user = User::firstOrNew(['telegram_id' => $telegramUser->get('id')]);

        if (! $user->exists) {
            $user->status = 'active';
            $user->joined_from = 'reseller_bot';
        }

        // B3.5: همان قاعده‌ی ربات اصلی — نام ویرایش‌شده‌ی کاربر با هر پیام بازنویسی نمی‌شود.
        app(ProfileCenterService::class)->applyTelegramName($user, $freshName);

        $user->save();

        app(UpdateRouter::class)->handle($reseller, $update, $user, (int) $chat->getId());

        return response('ok');
    }
}
