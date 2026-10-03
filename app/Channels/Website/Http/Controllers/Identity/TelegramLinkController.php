<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Channels\Website\Support\TelegramLoginVerifier;
use App\Services\Core\Identity\AccountLinkResult;
use App\Services\Core\Identity\AccountLinkingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Telegram-linking. پیشینه‌ی تصمیم: docs/history/PHASE-W3-PART3-TELEGRAM-LINKING.md.
 *
 * **این کنترلر «ورود با تلگرام» برای بازدیدکننده‌ی ناشناس نیست** —
 * عمداً پشت `auth` است. طبق Contract («Guest-to-Telegram
 * linking ... یک قابلیت اختیاریِ بعدی برای همین CustomerAccount، نه
 * پیش‌نیاز خرید»)، این فقط «وصل‌کردن» تلگرام به یک حساب از‌قبل‌موجود
 * است (User احراز‌شده با Register/Login؛ ساخت User از Guest وجود ندارد)، نه
 * یک مسیر ثبت‌نام/ورود جدید.
 *
 * فقط Main Context — برای نمایندگان ستون bot_username وجود ندارد.
 */
class TelegramLinkController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected TelegramLoginVerifier $verifier,
        protected AccountLinkingService $linking,
    ) {}

    public function callback(Request $request): RedirectResponse
    {
        $botToken = config('telegram.bots.main.token');
        $back = $this->websiteRoute($request, 'identity.profile.show');

        if (! $botToken) {
            return redirect($back)->withErrors(['telegram' => 'اتصال تلگرام در حال حاضر پیکربندی نشده است.']);
        }

        // ضد «Login/Link CSRF»: HMAC تلگرام روی محتوای تلگرام است، نه روی
        // این‌که «چه کسی» قصد وصل‌کردن دارد. بدون `state`، مهاجم می‌توانست
        // callback معتبر حساب تلگرام *خودش* را برای یک قربانیِ لاگین‌کرده
        // بفرستد و تلگرام مهاجم به حساب قربانی وصل شود. `state` یک‌بارمصرفِ
        // Session-bound این را می‌بندد: لینکِ کپی‌شده از Session مهاجم، در
        // Session قربانی مقدار متفاوتی (یا خالی) پیدا می‌کند.
        $expectedState = $request->session()->pull('telegram_link_state');

        if (! $expectedState || ! hash_equals($expectedState, (string) $request->query('state', ''))) {
            return redirect($back)->withErrors(['telegram' => 'درخواست نامعتبر یا منقضی‌شده بود. دوباره تلاش کنید.']);
        }

        $payload = $request->query();
        unset($payload['state']); // بخشی از data_check_string تلگرام نیست، نباید وارد محاسبه‌ی HMAC شود.

        if (! $this->verifier->verify($payload, $botToken)) {
            return redirect($back)->withErrors(['telegram' => 'تایید تلگرام نامعتبر بود. دوباره تلاش کنید.']);
        }

        $telegramId = (int) $payload['id'];

        // B2.4: تصمیم (مالکیت، جایگزینی بی‌صدا، Race، Audit) در Core است؛ اینجا فقط پیام نمایش داده می‌شود.
        $result = $this->linking->linkTelegram(
            $request->user(),
            $telegramId,
            trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')),
        );

        if ($result->isRejected()) {
            return redirect($back)->withErrors(['telegram' => match ($result->reason) {
                AccountLinkResult::REASON_OWNED_BY_OTHER => 'این حساب تلگرام قبلاً به یک کاربر دیگر متصل است.',
                AccountLinkResult::REASON_ALREADY_HAS_PROVIDER => 'به این حساب یک تلگرام دیگر وصل است. ابتدا آن را جدا کنید.',
                AccountLinkResult::REASON_RACE => 'این حساب تلگرام هم‌اکنون به کاربر دیگری متصل شد.',
                default => 'اتصال تلگرام انجام نشد.',
            }]);
        }

        if ($result->isAlready()) {
            return redirect($back)->with('status', 'این تلگرام از قبل به حساب شما وصل است.');
        }

        return redirect($back)->with('status', 'حساب تلگرام شما با موفقیت وصل شد.');
    }
}
