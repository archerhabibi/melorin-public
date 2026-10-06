<?php

namespace App\Services\Admin\Finance;

/** دریافت‌های یک روش پرداخت در بازه (B7.3). مبالغ int (Minor Unit). */
final class PaymentMethodFlow
{
    public function __construct(
        public readonly ?int $methodId,
        public readonly string $name,
        /** card_to_card / rial_gateway / crypto / other */
        public readonly ?string $type,
        /** تعداد شارژهای تأییدشده‌ی کیف‌پول‌های Main */
        public readonly int $charges,
        /** جمع شارژهای تأییدشده‌ی کیف‌پول‌های Main (ورودی پلتفرم) */
        public readonly int $collected,
        /** جمع شارژ کیف‌پول مشتریان فروشگاه‌های نمایندگان (صف نمایندگان) */
        public readonly int $storeCollected,
        /** بازگشت پرداخت از کیف‌پول‌های Main */
        public readonly int $refunded,
    ) {}

    /** خالص ورودی پلتفرم از این روش */
    public function net(): int
    {
        return $this->collected - $this->refunded;
    }
}
