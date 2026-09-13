<?php

namespace App\Services\Resellers;

use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * ResellerPricingService — طبق سند معماری، بخش ۳ (ResellerPricingService)
 * و سند نیازمندی بند ۵-۶: نماینده Catalog مستقل ندارد؛ فقط روی محصولِ
 * مجازِ سیستم اصلی می‌تواند «فعال/غیرفعال» و «قیمت فروش» تعیین کند، آن
 * هم در چهارچوب قوانین مرکزی (Reseller::min_sale_price_rule).
 */
class ResellerPricingService
{
    /**
     * تعیین/به‌روزرسانی قیمت فروش یک محصول برای یک نماینده. محصول را
     * به‌صورت ضمنی فعال هم می‌کند (چون تعیین قیمت بدون فعال‌سازی معنا
     * ندارد) — برای غیرفعال‌کردن صرف، از disable() استفاده شود.
     *
     * @throws InvalidArgumentException اگر قیمت یا سودِ حاصل خارج از محدوده‌ی مجاز مرکزی باشد
     */
    public function setSellingPrice(Reseller $reseller, Product $product, float $sellingPrice): ResellerProductPrice
    {
        $this->assertPriceAllowed($reseller, $product, $sellingPrice);

        return ResellerProductPrice::query()->updateOrCreate(
            ['reseller_id' => $reseller->id, 'product_id' => $product->id],
            ['custom_price' => $sellingPrice, 'is_enabled' => true],
        );
    }

    public function disable(Reseller $reseller, Product $product): void
    {
        ResellerProductPrice::query()
            ->where('reseller_id', $reseller->id)
            ->where('product_id', $product->id)
            ->update(['is_enabled' => false]);
    }

    public function isSellable(Reseller $reseller, Product $product): bool
    {
        return $product->sellingPriceForReseller($reseller) !== null;
    }

    /** لیست محصولاتی که همین الان برای این نماینده واقعاً قابل‌فروش‌اند (هم فعال در سیستم اصلی، هم فعال/قیمت‌گذاری‌شده توسط خودِ نماینده) */
    public function sellableProducts(Reseller $reseller): Collection
    {
        return Product::query()
            ->where('status', 'active')
            ->whereHas('resellerPrices', fn ($q) => $q->where('reseller_id', $reseller->id)->where('is_enabled', true))
            ->get();
    }

    /**
     * قوانین مرکزی قیمت‌گذاری (بند ۶ سند نیازمندی و بند ۲۱ سند نیازمندی
     * اصلیِ سیستم): حداقل/حداکثر قیمت فروش و حداقل/حداکثر سود. ساختار
     * min_sale_price_rule: ['min_price'=>?, 'max_price'=>?,
     * 'min_profit'=>?, 'max_profit'=>?] — هر کلید اختیاری است؛ کلید
     * غایب یعنی محدودیتی روی آن بعد وجود ندارد.
     */
    protected function assertPriceAllowed(Reseller $reseller, Product $product, float $sellingPrice): void
    {
        $rule = $reseller->min_sale_price_rule ?? [];
        $basePrice = (float) $product->price;
        $profit = $sellingPrice - $basePrice;

        if (isset($rule['min_price']) && $sellingPrice < (float) $rule['min_price']) {
            throw new InvalidArgumentException('قیمت فروش کمتر از حداقل مجاز است.');
        }

        if (isset($rule['max_price']) && $sellingPrice > (float) $rule['max_price']) {
            throw new InvalidArgumentException('قیمت فروش بیشتر از حداکثر مجاز است.');
        }

        if (isset($rule['min_profit']) && $profit < (float) $rule['min_profit']) {
            throw new InvalidArgumentException('سود این قیمت کمتر از حداقل مجاز است.');
        }

        if (isset($rule['max_profit']) && $profit > (float) $rule['max_profit']) {
            throw new InvalidArgumentException('سود این قیمت بیشتر از سقف مجاز است.');
        }

        if ($sellingPrice < $basePrice) {
            throw new InvalidArgumentException('قیمت فروش نمی‌تواند کمتر از قیمت پایه باشد.');
        }
    }
}
