<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * فاز W3 بند ۵ (نسخه‌ی محدود) — Guest Claiming Flow.
 *
 * «Claiming» در طراحی این پچ به‌معنای ادغام دو CustomerAccount نیست
 * (آن مسیر، `IdentityService::attachGuestToIdentity`، برای مهمان‌های
 * بدون User اصلاً پیش نمی‌آید — GuestPurchaseController از ابتدا یک
 * User واقعی ولی «ناقص» می‌سازد). اینجا Claiming یعنی: همان User ناقص
 * (تلفن دارد، رمز عبور ندارد) بتواند برای خودش رمز عبور بگذارد تا
 * دفعه‌ی بعد بدون خرید جدید هم بتواند وارد شود — دقیقاً همان «تکمیل
 * Identity» که بند Identity Completeness سند مادر توصیف کرده.
 *
 * اتصال Telegram (نیمه‌ی دوم بند ۵ + آنچه از W6 به اینجا منتقل شد) در
 * این پچ نیست؛ دلیل و برنامه‌ی پچ بعدی در
 * docs/PHASE-W3-PART2-GUEST-PURCHASE.md.
 */
class CompleteProfileController
{
    use ResolvesWebsiteRouteNames;

    public function show(Request $request, StoreContext $store): View
    {
        // پچ ۳.۲.۱۰ (Review امنیتی Telegram-linking، یافته‌ی اصلی):
        // یک state یک‌بارمصرف تولید می‌شود تا جلوی «Login/Link CSRF» را
        // بگیرد — جزئیات کامل در docs/PHASE-W6-PART5-TELEGRAM-REVIEW.md.
        $state = bin2hex(random_bytes(16));
        $request->session()->put('telegram_link_state', $state);

        return view('website.identity.complete-profile', [
            'user' => $request->user(),
            'store' => $store,
            'telegramBotUsername' => $store->isReseller() ? null : config('telegram.bots.main.username'),
            'telegramLinkState' => $state,
        ]);
    }

    public function store(Request $request, StoreContext $store): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        return redirect()
            ->to($this->websiteRoute($request, 'home'))
            ->with('status', 'رمز عبور با موفقیت تنظیم شد.');
    }
}
