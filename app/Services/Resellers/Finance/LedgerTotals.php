<?php

namespace App\Services\Resellers\Finance;

/** جمع‌های گردش اعتبار روی همان مجموعه‌ی فیلترشده‌ی جدول (B5.5). همه int؛ مبالغ همیشه مثبت (جهت در نام). */
final class LedgerTotals
{
    public function __construct(
        public readonly int $count,
        public readonly int $credited,
        public readonly int $debited,
        /** هزینه‌ی تأمین (reseller_price) که از اعتبار کسر شده */
        public readonly int $supplySpent,
        /** بازگشت هزینه‌ی تأمین بابت سفارش‌های بازگشت‌شده */
        public readonly int $supplyRefunded,
        public readonly int $charged,
        /** مجموع اصلاحات مدیر (علامت‌دار) */
        public readonly int $adjustedNet,
    ) {}

    /** خالص هزینه‌ی تأمین پس از بازگشت‌ها */
    public function netSupplyCost(): int
    {
        return $this->supplySpent - $this->supplyRefunded;
    }

    /** تغییر خالص اعتبار در این مجموعه (علامت‌دار) */
    public function netChange(): int
    {
        return $this->credited - $this->debited;
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
