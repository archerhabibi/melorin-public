<?php

namespace App\Services\Admin\Finance;

use Carbon\CarbonImmutable;

/** یک روز از روند مالی (B7.3). مبالغ int (Minor Unit). */
final class FinanceTrendPoint
{
    public function __construct(
        public readonly CarbonImmutable $day,
        /** درآمد پلتفرم از فروش قطعیِ ثبت‌شده در این روز */
        public readonly int $platformRevenue,
        /** شارژ تأییدشده‌ی کیف‌پول‌های Main در این روز */
        public readonly int $cashIn,
        /** بازگشت‌های این روز: بازگشت پرداخت + بازگشت وجه سفارش (سهم پلتفرم) */
        public readonly int $refunds,
    ) {}
}
