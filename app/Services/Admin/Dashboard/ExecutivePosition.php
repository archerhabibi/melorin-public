<?php

namespace App\Services\Admin\Dashboard;

/**
 * «وضعیت لحظه‌ای» پلتفرم (مستقل از بازه‌ی انتخابی): صف رسیدگی، سرویس‌ها، سرورها، تعهدات مالی (B7.1).
 */
final class ExecutivePosition
{
    public function __construct(
        public readonly int $activeUsers,
        public readonly int $activeServices,
        public readonly int $expiringServices,
        public readonly int $activeResellers,
        public readonly int $pendingPayments,
        public readonly int $pendingPaymentsAmount,
        /** در انتظار بیش از `STALE_PAYMENT_HOURS` ساعت */
        public readonly int $stalePayments,
        /** سفارش پرداخت‌شده‌ای که سرویسش ساخته نشده (provision_failed) */
        public readonly int $attentionOrders,
        /** پول گرفته شده و سرویس در راه است (paid/provisioning) */
        public readonly int $inProgressOrders,
        public readonly int $openTickets,
        public readonly int $openHighPriorityTickets,
        public readonly int $activeServers,
        public readonly int $serversDown,
        public readonly int $serversDegraded,
        /** سرور فعالی که ظرفیتش پر شده */
        public readonly int $serversFull,
        /** جمع ظرفیت و مصرف فقط روی سرورهای فعالِ دارای ظرفیت */
        public readonly int $capacityTotal,
        public readonly int $capacityUsed,
        /** جمع موجودی مثبت کیف‌پول‌های Main (پیش‌پرداخت نزد پلتفرم؛ شامل اعتبار نمایندگان) */
        public readonly int $prepaidBalance,
        /** جمع بدهی نمایندگان به پلتفرم (موجودی منفی کیف‌پول Main صاحبانِ نماینده) */
        public readonly int $resellerDebt,
        /** نماینده‌ی فعالی که قدرت خریدش (موجودی + سقف بدهی) ≤ ۰ است */
        public readonly int $resellersOutOfCredit,
    ) {}

    /** درصد صحیح مصرف ظرفیت (بدون ظرفیت تعریف‌شده ⇒ null) */
    public function capacityUsagePercent(): ?int
    {
        return $this->capacityTotal > 0
            ? intdiv($this->capacityUsed * 100 + intdiv($this->capacityTotal, 2), $this->capacityTotal)
            : null;
    }
}
