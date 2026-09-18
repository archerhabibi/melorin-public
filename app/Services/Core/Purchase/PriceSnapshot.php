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
 *     Customers_price  قیمت فروش نماینده به مشتریان خودش
 *
 * قوانین غیرقابل‌نقض بند ۲۰ که همین‌جا ساختاری اعمال می‌شوند:
 *
 *   Rule 2  خرید مستقیم Main        → Debit = main_price
 *   Rule 3  خرید مشتری از Reseller  → Customer Debit = Customers_price
 *   Rule 4  هزینه‌ی Reseller در Main → Reseller Debit = reseller_price
 *   Rule 6  Customers_price هرگز از کیف‌پول نماینده کسر نمی‌شود
 *   Rule 7  main_price هرگز در خرید از نماینده استفاده نمی‌شود
 *   Rule 8  reseller_price هرگز قیمت فروش به مشتری نیست
 *
 * چون Snapshot در هر Context فقط قیمت‌های همان Context را پر می‌کند،
 * استفاده‌ی اشتباهی از قیمتِ Context دیگر اصلاً ممکن نیست: در خرید
 * Main مقدار reseller_price و Customers_price صفر است و برعکس.
 */
final class PriceSnapshot
{
    public const CONTEXT_MAIN = 'main';

    public const CONTEXT_RESELLER = 'reseller';

    private function __construct(
        public readonly string $context,
        public readonly float $mainPrice,
        public readonly float $resellerPrice,
        public readonly float $customersPrice,
    ) {}

    /** خرید مستقیم از فروشگاه اصلی (بند ۸) — فقط main_price نقش دارد. */
    public static function forMainStore(Product $product): self
    {
        return new self(
            context: self::CONTEXT_MAIN,
            mainPrice: $product->mainPrice(),
            resellerPrice: 0.0,
            customersPrice: 0.0,
        );
    }

    /**
     * خرید مشتری از یک نماینده (بند ۹ و ۱۰) — دو قیمت مستقل:
     * مشتری Customers_price می‌پردازد، نماینده reseller_price.
     * main_price اینجا عمداً صفر است (Rule 7).
     */
    public static function forResellerStore(Product $product, float $customersPrice): self
    {
        return new self(
            context: self::CONTEXT_RESELLER,
            mainPrice: 0.0,
            resellerPrice: $product->resellerPrice(),
            customersPrice: $customersPrice,
        );
    }

    /** اکانت تست: هیچ پولی جابه‌جا نمی‌شود */
    public static function free(): self
    {
        return new self(self::CONTEXT_MAIN, 0.0, 0.0, 0.0);
    }

    public static function for(StoreContext $store, Product $product, ?float $customersPrice = null): self
    {
        if ($store->isMain()) {
            return self::forMainStore($product);
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
     * Main → main_price (Rule 2) · Reseller → Customers_price (Rule 3)
     */
    public function customerDebit(): float
    {
        return $this->isReseller() ? $this->customersPrice : $this->mainPrice;
    }

    /**
     * مبلغی که از کیف‌پول نماینده در Main کسر می‌شود (بند ۱۳، Debit دوم).
     * در فروش مستقیم Main اصلاً چنین کسری وجود ندارد.
     */
    public function resellerDebit(): float
    {
        return $this->isReseller() ? $this->resellerPrice : 0.0;
    }

    /** حاشیه‌ی نماینده (بند ۱۴) — فقط یک عدد گزارشی، نه مبنای هیچ Debit. */
    public function resellerProfit(): float
    {
        return $this->isReseller() ? $this->customersPrice - $this->resellerPrice : 0.0;
    }

    public function isFree(): bool
    {
        return $this->customerDebit() <= 0 && $this->resellerDebit() <= 0;
    }

    /**
     * نگاشت به ستون‌های جدول orders.
     *
     * ستون‌های فیزیکی عمداً تغییر نکرده‌اند (نصب‌های فعال نباید بشکنند)،
     * ولی معنایشان دقیقاً همان سه قیمت سند است و در کد فقط با
     * accessorهای Order::main_price / reseller_price / customers_price
     * خوانده می‌شوند:
     *
     *     خرید Main       core_price = sold_price = main_price
     *     خرید نمایندگی   core_price = reseller_price
     *                     sold_price = Customers_price
     */
    public function toOrderColumns(): array
    {
        $core = $this->isReseller() ? $this->resellerPrice : $this->mainPrice;

        return [
            // base_price برای سازگاری با گزارش‌ها و کدهای موجود نگه
            // داشته می‌شود و همان core_price است (بند ۱۳ بلوپرینت).
            'base_price' => $core,
            'core_price' => $core,
            'sold_price' => $this->customerDebit(),
        ];
    }
}
