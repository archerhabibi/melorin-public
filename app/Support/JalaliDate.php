<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * تبدیل میلادی ← شمسی (جلالی) بدون وابستگی خارجی و بدون نیاز به ext-intl (B3.3).
 *
 * فقط برای **نمایش** است؛ ذخیره‌سازی و فیلتر همچنان میلادی‌اند. الگوریتم حسابی (۳۳ ساله‌ی کبیسه)
 * برای سال‌های ۱۲۰۰ تا ۱۵۰۰ شمسی با تقویم رسمی یکی است.
 */
final class JalaliDate
{
    /** @return array{0:int,1:int,2:int} [سال، ماه، روز] شمسی */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /** `1405/07/12` یا با `$withTime` ⇒ `1405/07/12 14:30` */
    public static function format(CarbonInterface $date, bool $withTime = false): string
    {
        [$y, $m, $d] = self::fromGregorian((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));

        return sprintf('%04d/%02d/%02d', $y, $m, $d).($withTime ? ' '.$date->format('H:i') : '');
    }
}
