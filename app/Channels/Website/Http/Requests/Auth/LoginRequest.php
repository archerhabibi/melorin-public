<?php

namespace App\Channels\Website\Http\Requests\Auth;

use App\Services\Core\Identity\EmailIdentity;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    /** E1: Email قبل از اعتبارسنجی و کلید Rate Limit نرمال می‌شود. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailIdentity::normalize($this->input('email'))]);
        }
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

        // B2.1: User ساخته‌شده با Google «رمز ندارد» (password = NULL). Hash::check روی NULL در برخی
        // پیکربندی‌ها Exception می‌دهد (⇒ 500 و نشت وجود حساب). پس اگر Email هیچ User دارای رمزی ندارد،
        // همان مسیر شکست یکسان (پیام عمومی + Rate Limit + هزینه‌ی زمانیِ مشابه) اجرا می‌شود.
        // E1: یافتن User Case-insensitive؛ Auth::attempt با Email ذخیره‌شده‌ی همان User صدا زده می‌شود.
        $user = EmailIdentity::findUser($this->string('email')->toString());
        $hasPassword = $user !== null && $user->getRawOriginal('password') !== null;

        if (! $hasPassword) {
            try {
                // هزینه‌ی زمانیِ مشابه؛ Hash معتبر Bcrypt (۶۰ کاراکتر) تا Hash::check با `hashing.verify` پرتاب نکند.
                Hash::check((string) $this->input('password'), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
            } catch (\Throwable) {
                // فقط برای هم‌زمانی؛ شکست آن نباید مسیر خطای عمومی را عوض کند.
            }
        }

        // `status => active`: کاربر غیرفعال/مسدود نباید از Website وارد شود (همتای Google در G17).
        $credentials = ['email' => $user?->email ?? $this->input('email'), 'password' => $this->input('password'), 'status' => 'active'];

        if (! $hasPassword || ! Auth::guard('web')->attempt($credentials, $this->boolean('remember'))) {
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
