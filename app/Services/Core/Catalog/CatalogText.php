<?php

namespace App\Services\Core\Catalog;

/**
 * متن جست‌وجو/مقایسه‌ی کاتالوگ (B4.1) — خالص و بدون وابستگی.
 */
final class CatalogText
{
    /** trim، حذف نویسه‌ی کنترلی/جهت‌دهی، فاصله‌ی پیاپی ⇒ یکی، سقف طول */
    public static function clean(string $value, int $max): string
    {
        $value = preg_replace('/[\p{Cc}\x{200B}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $max);
    }

    /**
     * شکل مقایسه‌ای: ارقام فارسی/عربی ⇒ لاتین، «ي/ك/ە» عربی ⇒ «ی/ک»، حذف نیم‌فاصله و کشیده، حروف کوچک.
     * (نام تعرفه ممکن است با «۳۰ روزه» یا «30 روزه» یا «ي» عربی ذخیره شده باشد؛ جست‌وجو باید هر دو را بگیرد.)
     */
    public static function fold(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ە' => 'ه', 'ۀ' => 'ه', 'ة' => 'ه',
            "\u{200C}" => '', 'ـ' => '',
        ]);

        return mb_strtolower($value);
    }

    /** آیا همه‌ی واژه‌های `$needle` (جدا با فاصله) در `$haystack` هست؟ (AND؛ ترتیب مهم نیست) */
    public static function matches(string $haystack, string $needle): bool
    {
        $haystack = self::fold($haystack);

        foreach (array_filter(explode(' ', self::fold($needle)), fn ($w) => $w !== '') as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
