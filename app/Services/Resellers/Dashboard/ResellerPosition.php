<?php

namespace App\Services\Resellers\Dashboard;

/**
 * «وضعیت لحظه‌ای» نماینده (مستقل از بازه‌ی انتخابی): اعتبار، صف رسیدگی، سرویس‌ها (B5.1).
 */
final class ResellerPosition
{
    public function __construct(
        public readonly int $balance,
        public readonly int $debtLimit,
        public readonly int $pendingPayments,
        public readonly int $pendingPaymentsAmount,
        public readonly int $attentionOrders,
        public readonly int $inProgressOrders,
        public readonly int $totalCustomers,
        public readonly int $activeServices,
        public readonly int $expiringServices,
    ) {}

    /** بدهکار است؟ (موجودی منفی؛ فقط تا سقف بدهی مجاز است) */
    public function isInDebt(): bool
    {
        return $this->balance < 0;
    }

    /**
     * «قدرت خرید»: چقدر هنوز می‌شود از اعتبار کسر کرد = موجودی + سقف بدهی.
     * همان قاعده‌ی Reseller::minimumBalance() — موجودی بعد از کسر نباید از −سقف پایین‌تر برود.
     */
    public function purchasingPower(): int
    {
        return $this->balance + $this->debtLimit;
    }

    /** اعتبار تمام شده و فروش جدید (کسر از اعتبار) رد می‌شود */
    public function isOutOfCredit(): bool
    {
        return $this->purchasingPower() <= 0;
    }
}
