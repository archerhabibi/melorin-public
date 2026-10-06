<?php

namespace App\Services\Resellers\Marketing;

/**
 * جمع‌های مجموعه‌ی فیلترشده‌ی «اعضای معرفی‌شده» (B5.6). همه int؛ «پاداش» و «کمیسیون» دو شمارنده‌ی جدا (Master §11).
 */
final class ReferralTotals
{
    public function __construct(
        public readonly int $invited,
        /** معرفی‌شده‌هایی که حداقل یک خرید واقعی دارند (هم‌تعریف ReferralService::isFirstPurchase) */
        public readonly int $converted,
        public readonly int $referrers,
        /** مجموع فروش (customers_price) سفارش‌های خرید واقعیِ معرفی‌شده‌ها (همان وضعیت‌های «خرید واقعی») */
        public readonly int $revenue,
    ) {}

    /** نرخ تبدیل (٪) صحیح و گرد به نزدیک‌ترین؛ بدون معرفی‌شده ⇒ null */
    public function conversionPercent(): ?int
    {
        if ($this->invited <= 0) {
            return null;
        }

        return intdiv($this->converted * 100 + intdiv($this->invited, 2), $this->invited);
    }
}
