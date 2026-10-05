<?php

namespace App\Services\Resellers\Customers;

/** شمارنده‌های بالای فهرست مشتریان (B5.2). همه عدد صحیح؛ مبلغ Minor Unit. */
final class CustomerDirectorySummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $active,
        public readonly int $inactive,
        public readonly int $newInLast30Days,
        public readonly int $withActiveService,
        public readonly int $expiring,
        public readonly int $needsRenewal,
        public readonly int $neverBought,
        public readonly int $totalWalletBalance,
    ) {}
}
