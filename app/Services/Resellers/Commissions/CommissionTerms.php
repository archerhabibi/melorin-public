<?php

namespace App\Services\Resellers\Commissions;

/**
 * شرایط فعلی کمیسیون/پاداش — تنظیم سراسریِ مدیریت Melorin (`AffiliateSetting`)، نه تنظیم نماینده.
 * نماینده فقط می‌بیند؛ تغییرش با ادمین اصلی است و فقط روی خریدهای آینده اثر دارد
 * (رکوردهای گذشته با نرخ زمان خرید Snapshot شده‌اند، Master §11).
 */
final class CommissionTerms
{
    public function __construct(
        /** رشته‌ی دسیمال (مثلاً «5.00»)؛ نرخ است نه پول ⇒ float نیست */
        public readonly string $percent,
        public readonly int $validityDays,
        public readonly int $referrerBonus,
        public readonly int $customerBonus,
    ) {}

    public function commissionEnabled(): bool
    {
        // همان مقایسه‌ی CommissionService (رشته‌ی عددی، بدون float و بدون وابستگی به bcmath)
        return is_numeric($this->percent) && $this->percent > 0;
    }

    public function percentLabel(): string
    {
        return rtrim(rtrim($this->percent, '0'), '.') ?: '0';
    }
}
