<?php

namespace App\Support;

/**
 * درصد تغییر صحیح بین دو عدد (B7.1؛ از ResellerSummary بیرون کشیده شد تا داشبورد ادمین و نماینده
 * یک محاسبه‌ی واحد داشته باشند). فقط int؛ هیچ float مالی نیست.
 */
final class PercentChange
{
    /**
     * درصد تغییر نسبت به دوره‌ی قبل (عدد صحیح، گرد به نزدیک‌ترین).
     * بدون مبنا (دوره‌ی قبل صفر) ⇒ null — «۰ → X» درصدِ بی‌معنی است و نباید «∞٪» نشان داده شود.
     */
    public static function of(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        $diff = $current - $previous;
        $base = abs($previous);

        // محاسبه‌ی صحیح با گرد؛ برای مقادیر بسیار بزرگ (سرریز ×۱۰۰) از مقیاس‌کردن مبنا استفاده می‌شود.
        $percent = abs($diff) > intdiv(PHP_INT_MAX, 100)
            ? intdiv(abs($diff), max(1, intdiv($base, 100)))
            : intdiv(abs($diff) * 100 + intdiv($base, 2), $base);

        return $diff < 0 ? -$percent : $percent;
    }
}
