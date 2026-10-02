<?php

namespace App\Services\Core\Purchase;

use App\Models\Product;
use App\Services\Core\Store\StoreContext;

/**
 * بند ۱۲ بلوپرینت («قیمت‌ها در لحظه‌ی ایجاد Order باید Snapshot شوند»)
 * به‌علاوه‌ی بندهای ۳ تا ۷ و ۲۰ سند معماری Reseller.
 *
 * سیستم دقیقاً سه قیمت دارد و این کلاس تنها جایی است که هر سه با هم
 * دیده می‌شوند:
 *
 *     main_price       قیمت فروش مستقیم Main به مشتری Main
 *     reseller_price   قیمت تأمین محصول از Main برای نماینده
 *     customers_price  قیمت فروش نماینده به مشتریان خودش
 *
 * قوانین غیرقابل‌نقض بند ۲۰ که همین‌جا ساختاری اعمال می‌شوند:
 *
 *   Rule 2  خرید مستقیم Main        → Debit = main_price
 *   Rule 3  خرید مشتری از Reseller  → Customer Debit = customers_price
 *   Rule 4  هزینه‌ی Reseller در Main → Reseller Debit = reseller_price
 *   Rule 6  customers_price هرگز از کیف‌پول نماینده کسر نمی‌شود
 *   Rule 7  main_price هرگز در خرید از نماینده استفاده نمی‌شود
 *   Rule 8  reseller_price هرگز قیمت فروش به مشتری نیست
 *
 * چون Snapshot در هر Context فقط قیمت‌های همان Context را پر می‌کند،
 * استفاده‌ی اشتباهی از قیمتِ Context دیگر اصلاً ممکن نیست: در خرید
 * Main مقدار reseller_price و customers_price صفر است و برعکس.
 */
final class PriceSnapshot
{
    public const CONTEXT_MAIN = 'main';

    public const CONTEXT_RESELLER = 'reseller';

    private function __construct(
        public readonly string $context,
        public readonly int $mainPrice,
        public readonly int $resellerPrice,
        public readonly int $customersPrice,
    ) {}

    /**
     * خرید مستقیم از فروشگاه اصلی (بند ۸) — فقط main_price نقش دارد.
     *
     * $buyerOwnsAReseller — تصمیم صریح روی تناقض بند ۱۸ ↔ Rule 2/13
     * (مستند در docs/PHASE-14-FINAL-MODEL-TESTS.md، بخش «یافته‌های
     * نیازمند تصمیم»، مورد ۲): وقتی صاحبِ یک نماینده شخصاً از فروشگاه
     * اصلی خرید می‌کند، Context همچنان main می‌ماند (Rule 13 — او
     * مشتری مستقیم Main باقی می‌ماند)، اما مبلغِ کسرشده reseller_price
     * است، نه main_price — دقیقاً همان چیزی که بند ۱۸ سند می‌خواهد.
     * پیاده‌سازی عمداً همان‌جایی است که خودِ سند پیشنهاد داده بود: فقط
     * همین‌جا و با یک شرط، بدون تغییر مدل Context.
     */
    public static function forMainStore(Product $product, bool $buyerOwnsAReseller = false): self
    {
        return new self(
            context: self::CONTEXT_MAIN,
            mainPrice: $buyerOwnsAReseller ? $product->resellerPrice() : $product->mainPrice(),
            resellerPrice: 0,
            customersPrice: 0,
        );
    }

    /**
     * خرید مشتری از یک نماینده (بند ۹ و ۱۰) — دو قیمت مستقل:
     * مشتری customers_price می‌پردازد، نماینده reseller_price.
     * main_price اینجا عمداً صفر است (Rule 7).
     */
    public static function forResellerStore(Product $product, int $customersPrice): self
    {
        return new self(
            context: self::CONTEXT_RESELLER,
            mainPrice: 0,
            resellerPrice: $product->resellerPrice(),
            customersPrice: $customersPrice,
        );
    }

    /** اکانت تست: هیچ پولی جابه‌جا نمی‌شود */
    public static function free(): self
    {
        return new self(self::CONTEXT_MAIN, 0, 0, 0);
    }

    public static function for(
        StoreContext $store,
        Product $product,
        ?int $customersPrice = null,
        bool $buyerOwnsAReseller = false,
    ): self {
        if ($store->isMain()) {
            return self::forMainStore($product, $buyerOwnsAReseller);
        }

        if ($customersPrice === null) {
            throw new \InvalidArgumentException('برای فروش نمایندگی، قیمت فروش الزامی است.');
        }

        return self::forResellerStore($product, $customersPrice);
    }

    public function isReseller(): bool
    {
        return $this->context === self::CONTEXT_RESELLER;
    }

    /**
     * مبلغی که از کیف‌پول مشتری در Context خودش کسر می‌شود.
     * Main → main_price (Rule 2) · Reseller → customers_price (Rule 3)
     */
    public function customerDebit(): int
    {
        return $this->isReseller() ? $this->customersPrice : $this->mainPrice;
    }

    /**
     * مبلغی که از کیف‌پول نماینده در Main کسر می‌شود (بند ۱۳، Debit دوم).
     * در فروش مستقیم Main اصلاً چنین کسری وجود ندارد.
     */
    public function resellerDebit(): int
    {
        return $this->isReseller() ? $this->resellerPrice : 0;
    }

    /** حاشیه‌ی نماینده (بند ۱۴) — فقط یک عدد گزارشی، نه مبنای هیچ Debit. */
    public function resellerProfit(): int
    {
        return $this->isReseller() ? $this->customersPrice - $this->resellerPrice : 0;
    }

    public function isFree(): bool
    {
        return $this->customerDebit() <= 0 && $this->resellerDebit() <= 0;
    }

    /**
     * نگاشت به ستون‌های جدول orders.
     *
     * از مرحله ۵ (Pricing Migration) به بعد، ستون‌های فیزیکی هم دقیقاً
     * همین سه نام‌اند و هرکدام فقط در Context خودش پر می‌شود — نه یک
     * عدد مشترک زیر سه نام مختلف. سفارش Main فقط main_price دارد؛
     * سفارش نماینده فقط reseller_price و customers_price (بند ۳۱ سند).
     */
    public function toOrderColumns(): array
    {
        return [
            'main_price' => $this->isReseller() ? null : $this->mainPrice,
            'reseller_price' => $this->isReseller() ? $this->resellerPrice : null,
            'customers_price' => $this->isReseller() ? $this->customersPrice : null,
        ];
    }
}
