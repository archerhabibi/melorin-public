<?php

namespace App\Services\Core\Payments;

use App\Support\Money;

/**
 * پیش‌فاکتور پرداخت (B4.4) — خالص و فقط‌خواندنی: «قیمت نهایی تعرفه» در برابر «موجودی کیف‌پول».
 *
 * هیچ پولی جابه‌جا نمی‌کند و هیچ تصمیمی برای خرید نمی‌گیرد؛ مرجع واقعیِ کفایت موجودی همچنان
 * `PurchaseService`/`WalletService` (قفل ردیف) است. این کلاس فقط عددهایی را می‌سازد که صفحه‌ی Checkout و
 * صفحه‌ی شارژ باید نشان دهند (کمبود، موجودی پس از خرید، مبلغ پیشنهادی شارژ) تا هیچ Channel حساب‌وکتاب نکند.
 * همه‌ی مقادیر Minor Unit‌اند (Money).
 */
final class CheckoutQuote
{
    public function __construct(
        public readonly int $price,
        public readonly int $balance,
    ) {}

    public function isAffordable(): bool
    {
        return $this->balance >= $this->price;
    }

    /** مبلغی که برای این خرید کم است؛ ۰ اگر موجودی کافی باشد. */
    public function shortfall(): int
    {
        return max(0, $this->price - $this->balance);
    }

    /** موجودی پس از خرید؛ فقط وقتی کافی است معنا دارد، وگرنه null. */
    public function balanceAfter(): ?int
    {
        return $this->isAffordable() ? $this->balance - $this->price : null;
    }

    /**
     * مبلغ پیشنهادی شارژ: دقیقاً کمبود، ولی هیچ‌وقت کمتر از حداقل شارژ (پرداخت کمتر از حداقل رد می‌شود).
     * موجودی کافی ⇒ null (چیزی برای پیشنهاد نیست).
     */
    public function suggestedTopup(?int $minimum = null): ?int
    {
        if ($this->isAffordable()) {
            return null;
        }

        return max($this->shortfall(), $minimum ?? Money::minTopup());
    }
}
