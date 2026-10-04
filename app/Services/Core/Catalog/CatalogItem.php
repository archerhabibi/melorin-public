<?php

namespace App\Services\Core\Catalog;

use App\Models\Category;
use App\Models\Product;

/**
 * یک تعرفه‌ی قابل‌نمایش در یک Context (B4.1). قیمت از قبل نهایی است (main_price یا customers_price)؛ هیچ Channel
 * قیمت را محاسبه نمی‌کند. `soldOut` فقط **نمایش** است — مرجع واقعی ظرفیت همچنان رزرو اتمیک `PurchaseService` است.
 */
final class CatalogItem
{
    /** وقتی باقی‌مانده ≤ این عدد باشد «موجودی محدود» نشان داده می‌شود */
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        public readonly Product $product,
        public readonly Category $category,
        public readonly int $price,
    ) {}

    public function id(): int
    {
        return (int) $this->product->id;
    }

    /** null = بدون محدودیت */
    public function remaining(): ?int
    {
        if ($this->product->sale_limit === null) {
            return null;
        }

        return max(0, (int) $this->product->sale_limit - (int) $this->product->units_sold);
    }

    public function isSoldOut(): bool
    {
        return $this->remaining() === 0;
    }

    public function isLowStock(): bool
    {
        $remaining = $this->remaining();

        return $remaining !== null && $remaining > 0 && $remaining <= self::LOW_STOCK_THRESHOLD;
    }

    /** حجم نامحدود: `traffic_gb` خالی یا ≤ 0 (همان قاعده‌ی Account) */
    public function isUnlimitedTraffic(): bool
    {
        return $this->product->traffic_gb === null || (float) $this->product->traffic_gb <= 0;
    }

    public function trafficLabel(): string
    {
        if ($this->isUnlimitedTraffic()) {
            return 'نامحدود';
        }

        return rtrim(rtrim(number_format((float) $this->product->traffic_gb, 2, '.', ''), '0'), '.').' گیگابایت';
    }

    public function durationLabel(): string
    {
        return (int) $this->product->duration_days.' روز';
    }
}
