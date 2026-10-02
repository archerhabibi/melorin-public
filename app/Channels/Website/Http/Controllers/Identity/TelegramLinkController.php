<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Channels\Website\Support\TelegramLoginVerifier;
use App\Services\Core\AuditService;
use App\Services\Core\Store\IdentityService;
use Illuminate\Database\QueryException;
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
        protected IdentityService $identity,
        protected AuditService $audit,
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
        $currentUser = $request->user();

        $ownedBy = $this->identity->findIdentity(telegramId: $telegramId);

        if ($ownedBy && $ownedBy->id !== $currentUser->id) {
            // بند ۸۷ سند مادر: حتی با امضای معتبر تلگرام، اگر این
            // تلگرام از قبل به یک User دیگر وصل است، خودکار جابه‌جا/ادغام
            // نمی‌کنیم — چون معلوم نیست کدام طرف واقعاً صاحب همین
            // Session فعلی است.
            // تلاشِ ردشده برای وصل‌کردن یک هویت تلگرامیِ از‌قبل‌مالکیت‌دار:
            // یا سوءتفاهم کاربر است یا سوءاستفاده؛ در هر دو حالت باید Audit شود.
            $this->audit->record(
                'identity.telegram_link_rejected_owned_by_other',
                $currentUser,
                after: ['telegram_id' => $telegramId, 'owned_by_user_id' => $ownedBy->id],
                actor: $currentUser,
            );

            return redirect($back)->withErrors([
                'telegram' => 'این حساب تلگرام قبلاً به یک کاربر دیگر متصل است.',
            ]);
        }

        if ($ownedBy && $ownedBy->id === $currentUser->id) {
            return redirect($back)->with('status', 'این تلگرام از قبل به حساب شما وصل است.');
        }

        try {
            $currentUser->update([
                'telegram_id' => $telegramId,
                'full_name' => $currentUser->full_name ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? '')),
            ]);
        } catch (QueryException) {
            // Race: بین findIdentity و update یک درخواست دیگر همین
            // telegram_id را گرفت.
            return redirect($back)->withErrors([
                'telegram' => 'این حساب تلگرام هم‌اکنون به کاربر دیگری متصل شد.',
            ]);
        }

        // Audit روی «Identity Linking». طبق همان اصل
        // «Website چیزی جدید ثبت نمی‌کند، فقط AuditService موجود را صدا
        // می‌زند» (Website Contract) — نه یک جدول/مکانیزم لاگ جداگانه.
        $this->audit->record(
            'identity.telegram_linked',
            $currentUser,
            after: ['telegram_id' => $telegramId],
            actor: $currentUser,
        );

        return redirect($back)->with('status', 'حساب تلگرام شما با موفقیت وصل شد.');
    }
}
