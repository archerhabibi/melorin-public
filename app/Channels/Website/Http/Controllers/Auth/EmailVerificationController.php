<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Services\Core\Identity\EmailAuthService;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master 2.7 G11 / Website Contract §6 (شکاف C10) — تأیید Email.
 *
 * فقط UI/Flow؛ Enforcement (Gate روی Purchase و Wallet Charge) در Core است
 * (EmailVerificationGate). تا Verify نشدن، بقیه‌ی سایت آزاد است.
 *
 * Routeها عمداً فقط در Context اصلی هستند (نه زیر /store/{slug}): لینک
 * Email از صف/Queue ارسال می‌شود و Request/StoreContext ندارد؛ نام‌های
 * استاندارد Laravel (verification.*) استفاده شده‌اند.
 *
 * لینک: امضاشده (`signed`)، Expiration = config('auth.verification.expire')
 * (پیش‌فرض ۶۰ دقیقه)، و تأیید دوباره بی‌اثر است (Idempotent).
 */
class EmailVerificationController
{
    public function __construct(protected EmailAuthService $emailAuth) {}

    public function notice(Request $request): RedirectResponse|View
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('website.home'));
        }

        return view('website.auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        // fulfill(): علامت‌گذاری + رویداد Verified؛ برای کاربر تأییدشده بی‌اثر است.
        $wasVerified = $request->user()->hasVerifiedEmail();

        $request->fulfill();

        // E3: Audit فقط برای گذار واقعی «تأییدنشده ⇒ تأییدشده».
        $this->emailAuth->recordLinkVerification($request->user(), $wasVerified);

        return redirect()
            ->intended(route('website.home'))
            ->with('status', 'ایمیل شما تأیید شد.');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('website.home'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'لینک تأیید دوباره ارسال شد.');
    }
}
