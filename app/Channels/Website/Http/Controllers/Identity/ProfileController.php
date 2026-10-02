<?php

namespace App\Channels\Website\Http\Controllers\Identity;

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
 */
class ProfileController
{
    public function show(Request $request, StoreContext $store): View
    {
        // state یک‌بارمصرف Session-bound — ضد Login/Link CSRF.
        $state = bin2hex(random_bytes(16));
        $request->session()->put('telegram_link_state', $state);

        return view('website.identity.profile', [
            'user' => $request->user(),
            'store' => $store,
            'telegramBotUsername' => $store->isReseller() ? null : config('telegram.bots.main.username'),
            'telegramLinkState' => $state,
        ]);
    }
}
