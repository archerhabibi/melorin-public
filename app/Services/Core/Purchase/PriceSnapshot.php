<?php

namespace App\Services\Core\Purchase;

use App\Models\Product;
use App\Services\Core\Store\StoreContext;

/**
 * بند ۱۲ بلوپرینت — «در لحظه ایجاد Order باید قیمت‌ها Snapshot شوند.
 * نباید Order قدیمی با تغییر Product تغییر کند.»
 *
 * چرا این مهم است: امروز اگر ادمین قیمت یک محصول را عوض کند، هر
 * گزارشی که سود را از روی محصول حساب کند، سودِ سفارش‌های گذشته را هم
 * اشتباه نشان می‌دهد. سفارش باید عددِ لحظه‌ی خرید را برای همیشه نگه
 * دارد، حتی اگر محصول بعداً حذف شود.
 *
 * دو عدد، با معنای یکسان در هر دو نوع فروش:
 *     corePrice = چیزی که هسته دریافت می‌کند
 *     soldPrice = چیزی که مشتری می‌پردازد
 * تفاوتشان سود نماینده است — در فروش مستقیم صفر.
 */
final class PriceSnapshot
{
    private function __construct(
        public readonly float $corePrice,
        public readonly float $soldPrice,
    ) {}

    /**
     * فروش مستقیم فروشگاه اصلی: هسته همان چیزی را می‌گیرد که مشتری
     * می‌دهد.
     */
    public static function forMainStore(Product $product): self
    {
        return new self(
            corePrice: (float) $product->price,
            soldPrice: (float) $product->price,
        );
    }

    /**
     * فروش نمایندگی: مشتری قیمت فروشِ نماینده را می‌دهد، هسته قیمت
     * عمده را می‌گیرد، تفاوتش سود نماینده است.
     */
    public static function forResellerStore(Product $product, float $sellingPrice): self
    {
        return new self(
            corePrice: $product->resellerBasePrice(),
            soldPrice: $sellingPrice,
        );
    }

    /** اکانت تست: هیچ پولی جابه‌جا نمی‌شود */
    public static function free(): self
    {
        return new self(corePrice: 0.0, soldPrice: 0.0);
    }

    public static function for(StoreContext $store, Product $product, ?float $sellingPrice = null): self
    {
        if ($store->isMain()) {
            return self::forMainStore($product);
        }

        if ($sellingPrice === null) {
            throw new \InvalidArgumentException('برای فروش نمایندگی، قیمت فروش الزامی است.');
        }

        return self::forResellerStore($product, $sellingPrice);
    }

    public function resellerProfit(): float
    {
        return $this->soldPrice - $this->corePrice;
    }

    public function isFree(): bool
    {
        return $this->soldPrice <= 0 && $this->corePrice <= 0;
    }

    /** برای نوشتن مستقیم روی ستون‌های سفارش */
    public function toOrderColumns(): array
    {
        return [
            // base_price برای سازگاری با گزارش‌ها و کدهای موجود نگه
            // داشته می‌شود و همان core_price است (بند ۱۳: «برای
            // compatibility می‌توان فیلدهای قدیمی را حفظ کرد»).
            'base_price' => $this->corePrice,
            'core_price' => $this->corePrice,
            'sold_price' => $this->soldPrice,
        ];
    }
}
