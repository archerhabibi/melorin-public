<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * تصمیم ۹.۲: «Password reset — Breeze استاندارد (لینک ایمیل + Expiry)».
 * از همان broker پیش‌فرض 'users' که در config/auth.php از قبل تعریف
 * شده استفاده می‌کند — چیزی جدید ساخته نمی‌شود.
 */
class PasswordResetLinkController
{
    public function create(): View
    {
        return view('website.auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'ایمیل را وارد کنید.',
        ]);

        $status = Password::broker('users')->sendResetLink(
            $request->only('email')
        );

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => 'ارسال لینک بازیابی ممکن نشد. کمی بعد دوباره تلاش کنید.',
            ]);
        }

        return back()->with('status', 'لینک بازیابی رمز عبور برای ایمیل شما ارسال شد.');
    }
}
