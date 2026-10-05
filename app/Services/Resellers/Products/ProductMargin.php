<?php

namespace App\Services\Resellers\Products;

/**
 * سود هر فروش یک محصول = customers_price − reseller_price (B5.3). فقط محاسبه است (نه Debit — Master).
 * درصد با حساب صحیح و گردکردن به نزدیک‌ترین عدد؛ مبنای درصد reseller_price است. مبنای صفر ⇒ درصد null.
 */
final class ProductMargin
{
    private function __construct(
        public readonly int $profit,
        public readonly ?int $percent,
    ) {}

    public static function of(int $customersPrice, int $supplyPrice): self
    {
        $profit = $customersPrice - $supplyPrice;

        // فراتر از این حد ضرب ۲۰۰ سرریز می‌کند؛ مبلغ واقعی هرگز نزدیکش نمی‌رسد، ولی بی‌صدا غلط هم نشان نمی‌دهیم
        $percent = ($supplyPrice > 0 && abs($profit) < intdiv(PHP_INT_MAX, 200))
            ? intdiv(abs($profit) * 200 + $supplyPrice, 2 * $supplyPrice) * ($profit < 0 ? -1 : 1)
            : null;

        return new self($profit, $percent);
    }
}
