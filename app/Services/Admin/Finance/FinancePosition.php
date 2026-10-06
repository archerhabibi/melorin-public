<?php

namespace App\Services\Admin\Finance;

/**
 * وضعیت مالی لحظه‌ای پلتفرم (B7.3؛ مستقل از بازه). همه int (Minor Unit) یا شمارش.
 *
 * «تعهد» = پولی که پلتفرم به دیگران بدهکار است (موجودی مثبت کیف‌پول‌های Main)؛
 * «مطالبه» = پولی که نمایندگان به پلتفرم بدهکارند (موجودی منفی Wallet Main صاحبان نماینده، W3).
 */
final class FinancePosition
{
    public function __construct(
        /** جمع موجودی مثبت کیف‌پول Main مشتریان (غیرنماینده) */
        public readonly int $customerBalances,
        /** تعداد کیف‌پول‌های Main مشتریان با موجودی مثبت */
        public readonly int $customerWallets,
        /** جمع موجودی مثبت Wallet Main صاحبان نماینده (اعتبار پیش‌پرداخت تأمین) */
        public readonly int $resellerCredit,
        /** قدرمطلق جمع موجودی منفی Wallet Main صاحبان نماینده */
        public readonly int $resellerDebt,
        /** جمع موجودی مثبت کیف‌پول‌های فروشگاه نمایندگان (تعهد خود نمایندگان، نه پلتفرم) */
        public readonly int $resellerStoreBalances,
        /** کیف‌پول‌هایی که نباید منفی شوند ولی منفی‌اند (مشتری Main یا فروشگاه نماینده) */
        public readonly int $negativeWallets,
        public readonly int $negativeAmount,
        /** کیف‌پول‌هایی که موجودی‌شان ≠ مجموع گردش (Ledger) */
        public readonly int $unbalancedWallets,
        /** جمع علامت‌دارِ (موجودی − مجموع گردش) در کیف‌پول‌های ناتراز */
        public readonly int $ledgerDrift,
        public readonly int $pendingReceipts,
        public readonly int $pendingReceiptsAmount,
        public readonly int $staleReceipts,
        /** سفارش‌های `provision_failed`: پول گرفته شده، سرویس ساخته نشده */
        public readonly int $failedDeliveries,
        public readonly int $failedDeliveriesAmount,
        /** سفارش‌های `paid`/`provisioning`: پول گرفته شده، سرویس در راه */
        public readonly int $inDelivery,
        public readonly int $inDeliveryAmount,
        public readonly int $resellersOutOfCredit,
        public readonly int $resellersOverLimit,
    ) {}

    /** پیش‌پرداخت نزد پلتفرم (هم‌تعریف B7.1 `prepaidBalance`) */
    public function prepaidBalance(): int
    {
        return $this->customerBalances + $this->resellerCredit;
    }

    /** خالص تعهد پلتفرم = پیش‌پرداخت − مطالبات از نمایندگان؛ منفی یعنی طلب پلتفرم بیشتر است */
    public function netObligation(): int
    {
        return $this->prepaidBalance() - $this->resellerDebt;
    }

    /** پولی که گرفته شده و سرویسش هنوز تحویل نشده (ناموفق + در راه) */
    public function undeliveredAmount(): int
    {
        return $this->failedDeliveriesAmount + $this->inDeliveryAmount;
    }

    public function ledgerBalanced(): bool
    {
        return $this->unbalancedWallets === 0;
    }
}
