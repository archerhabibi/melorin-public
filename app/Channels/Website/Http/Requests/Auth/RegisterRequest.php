<?php

namespace App\Channels\Website\Http\Requests\Auth;

use App\Services\Core\Identity\EmailIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * تصمیم ۹.۷: «Laravel FormRequest (مرجع نهایی، سمت سرور)».
 * این تنها مرز واقعی اعتبارسنجی ثبت‌نام است؛ هر کمکی که سمت Client با
 * Alpine انجام شود صرفاً UX است، نه مرز امنیتی (بند ۹۰ سند مادر).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** E1: Email قبل از اعتبارسنجی و ذخیره trim + lowercase می‌شود. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailIdentity::normalize($this->input('email'))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // E1: یکتایی Case-insensitive و شامل Soft-deleted (مثل Unique Index).
                fn (string $attribute, mixed $value, \Closure $fail) => EmailIdentity::isTaken((string) $value)
                    ? $fail('این ایمیل قبلاً ثبت شده است.')
                    : null,
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'نام و نام خانوادگی را وارد کنید.',
            'email.required' => 'ایمیل را وارد کنید.',
            'email.email' => 'ایمیل واردشده معتبر نیست.',
            'email.unique' => 'این ایمیل قبلاً ثبت شده است.',
            'password.required' => 'رمز عبور را وارد کنید.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ];
    }
}
