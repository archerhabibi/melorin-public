<?php

namespace App\Channels\Website\Support;

/**
 * B2.5 — برچسب خوانا و کم‌افشا برای نشست‌ها (فقط نمایش، بدون وابستگی بیرونی).
 *
 * User-Agent را فقط برای «تشخیص دستگاه توسط خود مالک» خلاصه می‌کند و IP را ماسک می‌کند؛ چیزی که روی
 * صفحه می‌آید نباید با اسکرین‌شات یا بازدید گذرا اطلاعات دقیق شبکه‌ی کاربر را لو بدهد.
 */
final class DeviceLabel
{
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return 'دستگاه ناشناخته';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/'), str_contains($ua, 'EdgA/'), str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad'), str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $os !== null => $browser.' · '.$os,
            $browser !== null => $browser,
            $os !== null => $os,
            default => 'دستگاه ناشناخته',
        };
    }

    /** IPv4: دو بخش آخر ماسک می‌شود؛ IPv6: فقط دو گروه اول. */
    public static function maskIp(?string $ip): string
    {
        $ip = (string) $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.*.*';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);

            return ($parts[0] ?? '').':'.($parts[1] ?? '').':*';
        }

        return '—';
    }
}
