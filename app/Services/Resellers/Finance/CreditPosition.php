<?php

namespace App\Services\Resellers\Finance;

/**
 * وضعیت لحظه‌ای «اعتبار تأمین» نماینده (B5.5) = Wallet صاحب او در Main (Master W3؛ Wallet جدا ندارد).
 * همه‌ی مبالغ int (Minor Unit).
 */
final class CreditPosition
{
    public function __construct(
        public readonly int $balance,
        public readonly int $debtLimit,
        public readonly bool $hasWallet,
        /** شارژهای اعتبارِ خودِ نماینده که منتظر تأیید مدیریت Melorin‌اند */
        public readonly int $pendingChargeCount,
        public readonly int $pendingChargeAmount,
    ) {}

    /** موجودی + سقف بدهی؛ همان قاعده‌ی Reseller::minimumBalance() و ResellerPosition */
    public function purchasingPower(): int
    {
        return $this->balance + $this->debtLimit;
    }

    public function isInDebt(): bool
    {
        return $this->balance < 0;
    }

    public function isOutOfCredit(): bool
    {
        return $this->purchasingPower() <= 0;
    }

    /** بدهیِ فعلی (۰ اگر بدهکار نیست) */
    public function debt(): int
    {
        return $this->balance < 0 ? -$this->balance : 0;
    }
}
