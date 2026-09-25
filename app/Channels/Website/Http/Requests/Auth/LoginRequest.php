<?php

namespace App\Channels\Website\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تصمیم ۹.۶: «Login: ۵ تلاش/دقیقه به‌ازای IP+شناسه». محدودیت route-level
 * (throttle:5,1 در routes/website.php) کلی است؛ این محدودیتِ per-email
 * اضافی، دقیقاً همان چیزی است که Breeze استاندارد هم برای جلوگیری از
 * Credential Stuffing روی یک ایمیل خاص دارد.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'ایمیل یا رمز عبور اشتباه است.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "تعداد تلاش‌ها بیش از حد مجاز بود. {$seconds} ثانیه‌ی دیگر دوباره تلاش کنید.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
