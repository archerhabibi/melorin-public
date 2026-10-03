<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\GoogleOAuthClient;
use App\Services\Core\Identity\AccountLinkingService;
use App\Services\Core\Identity\SessionSecurityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * بازتعریف `/complete-profile`.
 *
 * قبلاً برای «User بدون رمز» (ساخته‌شده از Guest، مدل DEPRECATED) رمز
 * عبور می‌گرفت. در مدل جدید هر User با Register و رمز ساخته می‌شود، پس آن
 * قابلیت حذف شد. این صفحه فقط «پروفایل / اتصال Telegram» است (Master G10):
 * User احراز‌شده Telegram خودش را با ویجت رسمی وصل می‌کند؛ اعتبارسنجی HMAC و
 * ضد-CSRF (state یک‌بارمصرف) در TelegramLinkController است.
 *
 * B2.4: همین صفحه «روش‌های ورود و حساب‌های متصل» (Google، Telegram، رمز) را نشان می‌دهد.
 */
class ProfileController
{
    public function __construct(
        protected AccountLinkingService $linking,
        protected GoogleOAuthClient $google,
        protected SessionSecurityService $sessions,
    ) {}

    public function show(Request $request, StoreContext $store): View
    {
        // state یک‌بارمصرف Session-bound — ضد Login/Link CSRF.
        $state = bin2hex(random_bytes(16));
        $request->session()->put('telegram_link_state', $state);

        $user = $request->user();

        return view('website.identity.profile', [
            'user' => $user,
            // B2.4: وضعیت روش‌های ورود/اتصال (فقط خواندن؛ تصمیم‌ها در AccountLinkingService).
            'hasPassword' => $this->linking->hasPassword($user),
            'googleIdentity' => $this->linking->googleIdentity($user),
            'googleEnabled' => $this->google->enabled(),
            // B2.5: نشست‌های فعال (فقط با Session Driver = database؛ در غیر این صورت کارت نمایش داده نمی‌شود).
            'sessionsSupported' => $this->sessions->supported(),
            'activeSessions' => $this->sessions->activeFor($user, $request->session()->getId()),
            'store' => $store,
            'telegramBotUsername' => $store->isReseller() ? null : config('telegram.bots.main.username'),
            'telegramLinkState' => $state,
        ]);
    }
}
