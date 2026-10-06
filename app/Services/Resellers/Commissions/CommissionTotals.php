<?php

namespace App\Services\Resellers\Commissions;

/**
 * جمع‌های یک مجموعه‌ی فیلترشده از کمیسیون‌ها (B5.4). همه عدد صحیح؛ مبلغ Minor Unit.
 *
 * «کمیسیون» و «پاداش معرفی» عمداً دو شمارنده‌ی جدا هستند (Master §11: دو مفهوم مستقل)؛
 * هیچ‌جا با هم جمع نمی‌شوند مگر در `totalAmount()` که برچسبش «مجموع پرداختی» است.
 */
final class CommissionTotals
{
    public function __construct(
        public readonly int $commissionCount,
        public readonly int $commissionAmount,
        public readonly int $bonusCount,
        public readonly int $bonusAmount,
        public readonly int $referrers,
        public readonly int $referredCustomers,
        public readonly int $pendingCount,
        public readonly int $pendingAmount,
        /** پرداختی (هر دو نوع) روی سفارشِ بازگشت‌شده؛ طبق Master §10 خودکار برنمی‌گردد */
        public readonly int $refundedOrderCount,
        public readonly int $refundedOrderAmount,
        /** کمیسیون‌هایی که از کل سودِ همان سفارش بیشترند */
        public readonly int $exceedsProfitCount,
        /** مجموع سود سفارش‌هایی که کمیسیون درصدی گرفته‌اند (مبنای سهم کمیسیون از سود) */
        public readonly int $profitOnCommissionedOrders,
        /** تعداد رکوردهای «نیازمند توجه» (بازگشت‌شده یا بیشتر از سود) — هر رکورد یک بار، حتی اگر هر دو باشد */
        public readonly int $attentionCount,
    ) {}

    public function totalAmount(): int
    {
        return $this->commissionAmount + $this->bonusAmount;
    }

    public function isEmpty(): bool
    {
        return $this->commissionCount === 0 && $this->bonusCount === 0;
    }

    /**
     * سهم کمیسیون درصدی از سود سفارش‌های کمیسیون‌دار (عدد صحیح، گرد به نزدیک‌ترین).
     * مبنای صفر یا منفی ⇒ null (درصدِ بی‌معنی نشان داده نمی‌شود).
     */
    public function shareOfProfit(): ?int
    {
        if ($this->profitOnCommissionedOrders <= 0) {
            return null;
        }

        return intdiv($this->commissionAmount * 100 + intdiv($this->profitOnCommissionedOrders, 2), $this->profitOnCommissionedOrders);
    }
}
