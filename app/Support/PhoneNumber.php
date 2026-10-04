<?php

namespace App\Support;

/**
 * نرمال‌سازی شماره‌ی موبایل (B3.5) — خالص، بدون وابستگی، فقط برای **ذخیره و مقایسه**.
 *
 * فرمت استاندارد ذخیره: موبایل ایران `09xxxxxxxxx` (۱۱ رقم)؛ شماره‌ی خارجی `+` و ۸ تا ۱۵ رقم (E.164).
 * ورودی‌های رایج که به همین فرمت می‌رسند: ارقام فارسی/عربی، فاصله/خط‌تیره/پرانتز، `+989…`، `00989…`، `989…`، `9…`.
 * هر چیز دیگر `null` است (نامعتبر).
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = strtr($input, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        // فقط جداکننده‌های بی‌خطر حذف می‌شوند؛ حرف یا نماد دیگر ⇒ نامعتبر (نه «پاک‌سازی بی‌صدا»).
        if (preg_match('/^\+?[\d\s\-().\x{200C}\x{200F}\x{200E}]+$/u', trim($value)) !== 1) {
            return null;
        }

        $hasPlus = str_starts_with(trim($value), '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        // موبایل ایران در هر یک از شکل‌های 09… / 989… / 9…
        if (preg_match('/^(?:98|0)?(9\d{9})$/', $digits, $m) === 1 && ($hasPlus ? str_starts_with($digits, '98') : true)) {
            return '0'.$m[1];
        }

        if ($hasPlus && preg_match('/^\d{8,15}$/', $digits) === 1 && ! str_starts_with($digits, '0')) {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * همه‌ی شکل‌هایی که ممکن است برای همین شماره در ستون `users.phone` از قبل ذخیره شده باشد (ثبت‌نام قدیمی بدون
     * نرمال‌سازی). برای بررسی یکتایی؛ شماره‌ی خارجی فقط خودش.
     *
     * @return list<string>
     */
    public static function variants(string $canonical): array
    {
        if (preg_match('/^0(9\d{9})$/', $canonical, $m) !== 1) {
            return [$canonical];
        }

        return [$canonical, '+98'.$m[1], '98'.$m[1], '0098'.$m[1], $m[1]];
    }

    /** نمایش ماسک‌شده برای صفحه‌هایی که شماره را فقط «اعلام وضعیت» می‌کنند: `0912***6789` */
    public static function mask(string $phone): string
    {
        $len = mb_strlen($phone);

        return $len <= 7 ? $phone : mb_substr($phone, 0, 4).str_repeat('*', $len - 8).mb_substr($phone, -4);
    }
}
