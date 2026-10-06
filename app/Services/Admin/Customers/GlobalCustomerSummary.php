<?php

namespace App\Services\Admin\Customers;

/** شمارنده‌های بالای فهرست سراسری مشتریان (B7.2). همه عدد صحیح؛ مبلغ Minor Unit. */
final class GlobalCustomerSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $newInLast30Days,
        public readonly int $inactive,
        public readonly int $withActiveService,
        public readonly int $expiring,
        public readonly int $needsRenewal,
        public readonly int $neverBought,
        public readonly int $withBalance,
        public readonly int $multiStore,
        public readonly int $withOpenTicket,
        public readonly int $googleLinked,
        public readonly int $telegramLinked,
        public readonly int $unified,
        public readonly int $resellerOwners,
        public readonly int $mainWalletBalance,
    ) {}

    /** شمارنده‌ی هر بخش؛ همان عددی که فیلتر/تب همان بخش نشان می‌دهد (تعریف فقط در Core) */
    public function countFor(GlobalCustomerSegment $segment): int
    {
        return match ($segment) {
            GlobalCustomerSegment::ActiveService => $this->withActiveService,
            GlobalCustomerSegment::Expiring => $this->expiring,
            GlobalCustomerSegment::NeedsRenewal => $this->needsRenewal,
            GlobalCustomerSegment::NeverBought => $this->neverBought,
            GlobalCustomerSegment::HasBalance => $this->withBalance,
            GlobalCustomerSegment::MultiStore => $this->multiStore,
            GlobalCustomerSegment::OpenTicket => $this->withOpenTicket,
        };
    }
}
