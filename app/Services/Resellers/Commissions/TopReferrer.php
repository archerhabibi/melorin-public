<?php

namespace App\Services\Resellers\Commissions;

use Carbon\CarbonImmutable;

/** یک سطر «برترین معرف‌ها» (B5.4). */
final class TopReferrer
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly ?string $contact,
        public readonly int $commissionCount,
        public readonly int $commissionAmount,
        public readonly int $bonusAmount,
        public readonly int $referredCustomers,
        public readonly ?CarbonImmutable $lastPaidAt,
    ) {}

    public function totalAmount(): int
    {
        return $this->commissionAmount + $this->bonusAmount;
    }
}
