<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * تصمیم ۹.۲: «Password reset — Breeze استاندارد (لینک ایمیل + Expiry)».
 * از همان broker پیش‌فرض 'users' که در config/auth.php از قبل تعریف
 * شده استفاده می‌کند — چیزی جدید ساخته نمی‌شود.
 *
 * پچ ۳.۲.۷ (نفر ۴، فاز W6 بند ۱): بخش ۹.۶ صریحاً «۳/ساعت **به‌ازای
 * ایمیل**» خواسته، نه به‌ازای IP. `throttle:3,60` سطح Route (که همین
 * الان روی این مسیر هست) فقط IP/User را کلید می‌کند، نه مقدار فیلد
 * `email` را — یعنی یک مهاجم با چند IP می‌توانست همان ایمیل قربانی را
 * بیشتر از ۳ بار در ساعت Bombing کند. این‌جا همان الگوی دقیق
 * `LoginRequest::throttleKey()` تکرار شده، فقط کلید فقط `email` است
 * (بدون IP)، چون خودِ بند ۹.۶ فقط «به‌ازای ایمیل» گفته.
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

        $key = 'password-reset:'.Str::transliterate(Str::lower($request->string('email')));

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "تعداد درخواست‌ها بیش از حد مجاز بود. ".ceil($seconds / 60)." دقیقه‌ی دیگر دوباره تلاش کنید.",
            ]);
        }

        RateLimiter::hit($key, 3600);

        // نتیجه‌ی broker عمداً نادیده گرفته می‌شود: نشان دادن خطا برای ایمیلِ
        // ناموجود به مهاجم اجازه می‌داد ثبت‌نام بودن ایمیل‌ها را حدس بزند
        // (User Enumeration). پاسخ همیشه یکسان است؛ تنها خطای مجاز همان
        // محدودیت تعداد درخواست بالاست.
        Password::broker('users')->sendResetLink($request->only('email'));

        return back()->with('status', 'اگر این ایمیل در سیستم ثبت باشد، لینک بازیابی رمز عبور برای آن ارسال می‌شود.');
    }
}
