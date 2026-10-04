<?php

namespace App\Channels\TelegramBot\Http\Controllers;

use App\Channels\TelegramBot\UpdateRouter;
use App\Models\User;
use App\Services\Core\Customer\ProfileCenterService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/**
 * تنها نقطه‌ی ورودی ربات اصلی. مسئولیت این کنترلر فقط چهار چیز است:
 * ۱) راستی‌آزمایی درخواست، ۲) جلوگیری از پردازش دوباره‌ی همان Update
 * (تلگرام اگر پاسخ webhook کند نرسد یا دیر برسد، همان Update را دوباره
 * می‌فرستد — بدون این محافظت، عملیات کند مثل ساخت اکانت روی پنل واقعی
 * می‌تواند دوبار اجرا شود و دو اکانت/دو کسر کیف‌پول برای یک کلیک ایجاد
 * کند)، ۳) پیدا/ساخت کاربر مرکزی (بند ۲۵)، ۴) واگذاری کامل منطق به
 * UpdateRouter. هیچ منطق کسب‌وکاری اینجا نوشته نمی‌شود (بند ۳۴ سند).
 */
class WebhookController
{
    public function __construct(protected Api $telegram, protected UpdateRouter $router) {}

    public function __invoke(Request $request, string $token): Response
    {
        if (! hash_equals((string) config('telegram.bots.main.token'), $token)) {
            abort(404);
        }

        // Fail-closed (فاز ۸، S-02): پیش از این اگر TELEGRAM_WEBHOOK_SECRET خالی بود
        // چک کلاً رد می‌شد (Fail-open) و مقایسه هم زمان‌ثابت نبود. اکنون بدون
        // secret پیکربندی‌شده، هیچ Updateای پذیرفته نمی‌شود.
        $secret = (string) config('telegram.webhook_secret');

        if ($secret === '') {
            Log::error('telegram_webhook_secret_not_configured');

            abort(403);
        }

        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = new Update($request->all());

        // محافظت در برابر تحویل دوباره‌ی همان Update توسط تلگرام. هر
        // Update یک update_id یکتا و صعودی دارد؛ اگر قبلاً دیده شده،
        // بی‌سروصدا نادیده گرفته می‌شود (نه خطا، چون از دید تلگرام این
        // یک تلاش مجدد عادی است، نه یک درخواست خراب).
        $updateId = $update->get('update_id');

        if ($updateId !== null) {
            $cacheKey = "telegram_update_seen_{$updateId}";

            // Cache::add اتمیک است (add-if-absent). الگوی قبلی
            // has()+put() یک check-then-act بود: دو تحویلِ هم‌زمانِ یک
            // Update توسط تلگرام می‌توانستند هر دو has()=false ببینند و
            // هر دو پردازش شوند — دقیقاً همان چیزی که این محافظ قرار
            // بود جلویش را بگیرد (High #10 گزارش امنیتی).
            if (! Cache::add($cacheKey, true, now()->addHours(6))) {
                return response('ok');
            }
        }

        // توجه: عمداً از $update->get('message') / $update->get('callback_query')
        // استفاده می‌شود (نه getMessage()/getCallbackQuery()). این دو در تست
        // روی سرور واقعی برای کلیک روی دکمه‌ی این‌لاین («خرید اکانت» →
        // انتخاب سبد فروش) رفتار متفاوتی داشتند؛ با getMessage()/getCallbackQuery()
        // پردازش callback_query به‌خاموشی متوقف می‌شد (نه خطا در لاگ، نه
        // پاسخ به کاربر). اگر در آینده این رفتار دوباره دیده شد، اول اینجا
        // را بررسی کنید — نسخه‌ی get() ثابت شده کار می‌کند.
        $message = $update->get('message');
        $callbackQuery = $update->get('callback_query');

        $telegramUser = $message ? $message->get('from') : ($callbackQuery ? $callbackQuery->get('from') : null);
        $chat = $message ? $message->getChat() : ($callbackQuery ? $callbackQuery->getMessage()->getChat() : null);

        if (! $telegramUser || ! $chat) {
            return response('ok');
        }

        // محافظت حیاتی: اگر فرستنده‌ی این آپدیت خودِ ربات باشد (is_bot),
        // یعنی تلگرام پیام خروجیِ خودِ ربات را به‌عنوان یک آپدیت ورودیِ
        // جدید echo کرده — سناریویی که وقتی ویژگی Telegram Business روی
        // اکانت متصل به این ربات فعال است رخ می‌دهد (تنظیمات تلگرام ←
        // Telegram Business ← Chatbots). بدون این محافظت، سیستم پیام‌های
        // خودِ ربات را به‌اشتباه به‌عنوان اقدام یک کاربر واقعی («ربات» با
        // telegram_id خودش) پردازش می‌کند و باعث می‌شود خرید/شارژ از
        // حساب اشتباه کسر شود — دقیقاً همان باگی که گزارش شد.
        if ($telegramUser->get('is_bot')) {
            return response('ok');
        }

        $freshName = trim($telegramUser->get('first_name').' '.$telegramUser->get('last_name'));

        // لاگ تشخیصی دائمی: نگه داشته می‌شود چون یک‌بار همین لاگ باعث پیدا
        // شدن و رفع قطعی یک باگ واقعی شد (echo پیام‌های خودِ ربات به‌خاطر
        // Telegram Business — رفع‌شده در بالا با فیلتر is_bot). برای هر
        // آپدیت واقعی کاربر، دقیقاً مشخص می‌کند from.id واقعی و نوع
        // آپدیت (پیام/کلیک روی دکمه) چه بوده — در صورت بروز هر مشکل
        // مشابه در آینده (کسر از حساب اشتباه، رفتار غیرمنتظره)، اولین
        // جایی است که باید بررسی شود.
        Log::info('telegram_update_resolved', [
            'update_id' => $updateId,
            'type' => $message ? 'message' : ($callbackQuery ? 'callback_query' : 'unknown'),
            'from_id' => $telegramUser->get('id'),
            'chat_id' => $chat->getId(),
        ] + (config('app.debug') ? [
            // O-6 (فاز ۹): نام و متن پیام PII است؛ فقط در حالت Debug لاگ می‌شود.
            'from_first_name' => $telegramUser->get('first_name'),
            'text_or_data' => $message ? $message->getText() : ($callbackQuery ? $callbackQuery->get('data') : null),
        ] : []));

        $user = User::firstOrNew(['telegram_id' => $telegramUser->get('id')]);

        if (! $user->exists) {
            $user->status = 'active';
            $user->joined_from = 'bot';
        }

        // نام نمایشی تلگرام فقط روی نامی می‌نشیند که «متعلق به تلگرام» است (B3.5): کاربری که نامش را در پروفایل
        // (سایت یا ربات) ویرایش کرده، یا از Website ثبت‌نام کرده و بعد تلگرام وصل کرده، نامش با هر پیام بازنویسی
        // نمی‌شود. قاعده‌ی واحد در Core است (`ProfileCenterService::applyTelegramName`)؛ شناسه‌ی عددی تلگرام
        // (telegram_id) همچنان تنها کلید اتکاست و full_name صرفاً برچسب نمایشی است.
        app(ProfileCenterService::class)->applyTelegramName($user, $freshName);

        $user->save();

        // عضویت در Main (Rule 12): هر کاربرِ ربات اصلی یک CustomerAccount در
        // Main دارد؛ مخاطبِ پیام همگانی و Scope فقط از همین عضویت می‌آید،
        // نه از users.reseller_id. Idempotent است.
        app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        $this->router->handle($update, $user, (int) $chat->getId());

        return response('ok');
    }
}
