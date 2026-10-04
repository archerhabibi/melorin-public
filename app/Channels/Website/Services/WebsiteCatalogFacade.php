<?php

namespace App\Channels\Website\Services;

use App\Models\Product;
use App\Services\Core\Catalog\Catalog;
use App\Services\Core\Catalog\CatalogItem;
use App\Services\Core\Catalog\CatalogQuery;
use App\Services\Core\Catalog\ProductCatalogService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Collection;

/**
 * «لایه‌ی نازک Website Facade Services — فقط متدهای موجود Core را صدا
 * می‌زند، هیچ منطق تصمیم‌گیری در این لایه نیست». از B4.1 تمام منطق دیده‌شدن،
 * قیمت نمایشی، جست‌وجو/فیلتر/مرتب‌سازی و ظرفیت در `ProductCatalogService` (Core) است
 * و ربات‌ها هم از همان می‌خوانند؛ این کلاس فقط Adapter است.
 */
class WebsiteCatalogFacade
{
    public function __construct(protected ProductCatalogService $core) {}

    /** @param  array<string, mixed>  $input  query string خام؛ پاک‌سازی در Core (`CatalogQuery::fromInput`) */
    public function catalog(StoreContext $store, array $input = []): Catalog
    {
        return $this->core->catalog($store, CatalogQuery::fromInput($input));
    }

    public function item(int $productId, StoreContext $store): ?CatalogItem
    {
        return $this->core->find($productId, $store);
    }

    /** @return Collection<int, CatalogItem> */
    public function alternatives(CatalogItem $item, StoreContext $store, int $limit = 3): Collection
    {
        return $this->core->alternatives($item, $store, $limit);
    }

    /**
     * برای Checkout/Guest/Charge: همان قاعده‌ی دیده‌شدن کاتالوگ (از B4.1 شامل «سبد فعال» در فروشگاه اصلی هم هست).
     */
    public function findVisibleProduct(int $productId, StoreContext $store): ?Product
    {
        return $this->core->find($productId, $store)?->product;
    }

    /** قیمت نمایشی — همان عددی که کاتالوگ نشان می‌دهد (main_price یا customers_price)؛ هیچ محاسبه‌ای در Channel نیست. */
    public function displayPrice(Product $product, StoreContext $store): int
    {
        return (int) ($this->core->find((int) $product->id, $store)?->price ?? 0);
    }
}
