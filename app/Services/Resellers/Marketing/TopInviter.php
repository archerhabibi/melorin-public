<?php

namespace App\Services\Resellers\Marketing;

/** یک سطر «برترین معرف‌ها» بر اساس تعداد معرفی‌شده (B5.6). */
final class TopInviter
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $contact,
        public readonly int $invited,
        public readonly int $converted,
        /** کمیسیون خرید + پاداش معرفی که همین معرف در این فروشگاه گرفته (جدا از هم) */
        public readonly int $commissionEarned,
        public readonly int $bonusEarned,
    ) {}

    public function conversionPercent(): ?int
    {
        return $this->invited <= 0 ? null : intdiv($this->converted * 100 + intdiv($this->invited, 2), $this->invited);
    }

    public function totalEarned(): int
    {
        return $this->commissionEarned + $this->bonusEarned;
    }
}
