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
