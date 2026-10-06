<?php

namespace App\Support\Branding;

/**
 * محاسبات رنگ Brand (B1.4) — خالص و بدون وابستگی، تا هم Layout وب و هم پنل Filament
 * از یک منبع استفاده کنند. ورودی همیشه hex شش‌رقمی معتبر است
 * (ResellerWebsiteSetting::brandingFor() قبلاً اعتبارسنجی می‌کند)؛ ورودی نامعتبر به پیش‌فرض می‌افتد.
 *
 * مبنا: WCAG 2.x (روشنایی نسبی و نسبت contrast).
 */
final class BrandColor
{
    public const DEFAULT = '#2563eb';

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#111827';

    /** سطح صفحه در حالت روشن/تیره (همان `--c-surface` در tokens.css)؛ مبنای خوانایی «متن/لینک برند». */
    public const LIGHT_SURFACE = '#ffffff';

    public const DARK_SURFACE = '#14181f';

    /** حداقل contrast برای متن معمولی (WCAG AA). */
    public const TEXT_MIN_CONTRAST = 4.5;

    /** رنگ متن روی پس‌زمینه‌ی Brand: سفید یا تیره، هرکدام contrast بیشتری دارد */
    public static function onColor(string $hex): string
    {
        $hex = self::normalize($hex);

        return self::contrast($hex, self::LIGHT_TEXT) >= self::contrast($hex, self::DARK_TEXT)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    /**
     * نسخه‌ای از رنگ که متن سفید روی آن حداقل $min contrast دارد (برای primary پنل Filament که
     * متن دکمه‌هایش سفید است). رنگ‌های روشن به‌تدریج تیره می‌شوند؛ رنگ‌های کافی دست‌نخورده می‌مانند.
     */
    public static function readableWithWhite(string $hex, float $min = 3.0): string
    {
        $hex = self::normalize($hex);

        for ($i = 0; $i < 12 && self::contrast($hex, self::LIGHT_TEXT) < $min; $i++) {
            [$r, $g, $b] = self::rgb($hex);
            $hex = sprintf('#%02x%02x%02x', (int) round($r * 0.85), (int) round($g * 0.85), (int) round($b * 0.85));
        }

        return $hex;
    }

    /**
     * نسخه‌ای از رنگ که به‌عنوان **متن/لینک** روی سطح روشن حداقل $min contrast دارد (B6.2).
     * رنگ کافی دست‌نخورده می‌ماند؛ رنگ روشن (مثل زرد) به‌تدریج به سمت سیاه می‌رود. همیشه به نتیجه می‌رسد.
     */
    public static function textOnLight(string $hex, float $min = self::TEXT_MIN_CONTRAST): string
    {
        return self::readableOn($hex, self::LIGHT_SURFACE, $min);
    }

    /** مثل `textOnLight` ولی روی سطح تیره؛ رنگ‌های تیره (مثل سرمه‌ای) به سمت سفید روشن می‌شوند. */
    public static function textOnDark(string $hex, float $min = self::TEXT_MIN_CONTRAST): string
    {
        return self::readableOn($hex, self::DARK_SURFACE, $min);
    }

    /**
     * رنگ را فقط در راستای خوانایی روی $surface تغییر می‌دهد: اگر سطح روشن‌تر از رنگ است به سمت سیاه، وگرنه
     * به سمت سفید. گام‌های ریز تا «نزدیک‌ترین» رنگ خوانا انتخاب شود (هویت رنگ تا جای ممکن حفظ می‌شود).
     */
    public static function readableOn(string $hex, string $surface, float $min = self::TEXT_MIN_CONTRAST): string
    {
        $hex = self::normalize($hex);

        if (self::contrast($hex, $surface) >= $min) {
            return $hex;
        }

        $target = self::luminance($surface) > 0.5 ? '#000000' : '#ffffff';

        for ($step = 1; $step <= 20; $step++) {
            $candidate = self::mix($hex, $target, $step / 20);

            if (self::contrast($candidate, $surface) >= $min) {
                return $candidate;
            }
        }

        return $target;
    }

    /** ترکیب خطی دو رنگ (t=0 ⇒ $a، t=1 ⇒ $b). */
    public static function mix(string $a, string $b, float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        [$ar, $ag, $ab] = self::rgb(self::normalize($a));
        [$br, $bg, $bb] = self::rgb(self::normalize($b));

        return sprintf(
            '#%02x%02x%02x',
            (int) round($ar + ($br - $ar) * $t),
            (int) round($ag + ($bg - $ag) * $t),
            (int) round($ab + ($bb - $ab) * $t),
        );
    }

    /**
     * گزارش خوانایی یک رنگ Brand برای فرم تنظیمات (خالص): نسبت contrast متن دکمه و آیا رنگ برای
     * متن روی سطح روشن/تیره باید اصلاح شود. فقط راهنما است؛ هیچ رنگی رد نمی‌شود.
     *
     * @return array{button: float, light_text: string, dark_text: string, light_adjusted: bool, dark_adjusted: bool}
     */
    public static function report(string $hex): array
    {
        $hex = self::normalize($hex);
        $light = self::textOnLight($hex);
        $dark = self::textOnDark($hex);

        return [
            'button' => round(self::contrast($hex, self::onColor($hex)), 1),
            'light_text' => $light,
            'dark_text' => $dark,
            'light_adjusted' => $light !== $hex,
            'dark_adjusted' => $dark !== $hex,
        ];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb(self::normalize($hex)));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function normalize(string $hex): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtolower($hex) : self::DEFAULT;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }
}
