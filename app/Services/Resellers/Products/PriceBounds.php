<?php

namespace App\Services\Resellers\Products;

use App\Models\Product;
use App\Models\Reseller;

/**
 * محدوده‌ی مجازِ قیمت فروش (customers_price) یک محصول برای یک نماینده (B5.3).
 *
 * تنها منبع قوانین قیمت‌گذاری است: ResellerPricingService::assertPriceAllowed دقیقاً از violation() استفاده
 * می‌کند و پنل همین شیء را برای راهنمای فرم/پیش‌نمایش نشان می‌دهد؛ پس «آنچه فرم می‌گوید مجاز است» و «آنچه
 * ثبت می‌شود» از دو جا نمی‌آید. ترتیب و متن پیام‌ها همان رفتار پیش از B5.3 است.
 *
 * قوانین (Reseller::min_sale_price_rule؛ کلید غایب = بدون محدودیت): min_price، max_price، min_profit، max_profit
 * و کف مطلق reseller_price (Rule 8). سود = customers_price − reseller_price.
 */
final class PriceBounds
{
    public const MSG_MIN_PRICE = 'قیمت فروش کمتر از حداقل مجاز است.';

    public const MSG_MAX_PRICE = 'قیمت فروش بیشتر از حداکثر مجاز است.';

    public const MSG_MIN_PROFIT = 'سود این قیمت کمتر از حداقل مجاز است.';

    public const MSG_MAX_PROFIT = 'سود این قیمت بیشتر از سقف مجاز است.';

    public const MSG_BELOW_SUPPLY = 'قیمت فروش نمی‌تواند کمتر از قیمت نمایندگان (reseller_price) باشد.';

    /** @param array<string, mixed> $rule */
    public function __construct(
        public readonly int $supplyPrice,
        private readonly array $rule = [],
    ) {}

    public static function for(Reseller $reseller, Product $product): self
    {
        return new self($product->resellerPrice(), $reseller->min_sale_price_rule ?? []);
    }

    /** پیام نخستین قاعده‌ی نقض‌شده یا null اگر قیمت مجاز است (ترتیب: همان قدیم) */
    public function violation(int $customersPrice): ?string
    {
        $profit = $customersPrice - $this->supplyPrice;

        return match (true) {
            isset($this->rule['min_price']) && $customersPrice < (int) $this->rule['min_price'] => self::MSG_MIN_PRICE,
            isset($this->rule['max_price']) && $customersPrice > (int) $this->rule['max_price'] => self::MSG_MAX_PRICE,
            isset($this->rule['min_profit']) && $profit < (int) $this->rule['min_profit'] => self::MSG_MIN_PROFIT,
            isset($this->rule['max_profit']) && $profit > (int) $this->rule['max_profit'] => self::MSG_MAX_PROFIT,
            $customersPrice < $this->supplyPrice => self::MSG_BELOW_SUPPLY,
            default => null,
        };
    }

    public function allows(int $customersPrice): bool
    {
        return $this->violation($customersPrice) === null;
    }

    /** کمترین قیمت مجاز (همیشه مقدار دارد: دست‌کم reseller_price) */
    public function min(): int
    {
        $min = $this->supplyPrice;

        if (isset($this->rule['min_price'])) {
            $min = max($min, (int) $this->rule['min_price']);
        }

        if (isset($this->rule['min_profit'])) {
            $min = max($min, $this->supplyPrice + (int) $this->rule['min_profit']);
        }

        return $min;
    }

    /** بیشترین قیمت مجاز یا null (بدون سقف) */
    public function max(): ?int
    {
        $caps = [];

        if (isset($this->rule['max_price'])) {
            $caps[] = (int) $this->rule['max_price'];
        }

        if (isset($this->rule['max_profit'])) {
            $caps[] = $this->supplyPrice + (int) $this->rule['max_profit'];
        }

        return $caps === [] ? null : min($caps);
    }

    /** قوانین مرکزی طوری‌اند که هیچ قیمتی مجاز نیست (کف از سقف بالاتر) */
    public function isEmpty(): bool
    {
        $max = $this->max();

        return $max !== null && $this->min() > $max;
    }
}
