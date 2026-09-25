<?php

namespace App\Channels\Website\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\Core\Store\StoreContext;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Database\Eloquent\Collection;

/**
 * فاز W0 بند ۵ Roadmap: «لایه‌ی نازک Website Facade Services — فقط
 * متدهای موجود Core را صدا می‌زند، هیچ منطق تصمیم‌گیری در این لایه
 * نیست». این کلاس مشخصاً معادل بند ۱۴/۱۵/۱۶ زیرسند (Home + Product
 * Detail) است: فقط Query و صدا زدن متدهای قیمتِ خودِ مدل‌ها — هیچ
 * محاسبه‌ی قیمتی در Client/Controller انجام نمی‌شود (بند ۱ Roadmap).
 */
class WebsiteCatalogFacade
{
    public function __construct(protected ResellerPricingService $pricing) {}

    /**
     * دسته‌بندی‌های فعال به‌همراه محصولات قابل‌فروش‌شان در این Context.
     * در فروشگاه اصلی همه‌ی محصولات فعال نمایش داده می‌شوند؛ در فروشگاه
     * نماینده فقط آن‌هایی که ResellerPricingService::isSellable تایید
     * می‌کند (دقیقاً همان Guard که Core خودِ خرید را هم با آن چک می‌کند
     * — بند ۹.۴: «بدون منطق اضافه در Website»).
     */
    public function categoriesWithProducts(StoreContext $store): Collection
    {
        return Category::query()
            ->where('status', 'active')
            ->with(['products' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->map(function (Category $category) use ($store) {
                $category->setRelation(
                    'products',
                    $category->products->filter(fn (Product $p) => $this->isVisible($p, $store))->values()
                );

                return $category;
            })
            ->filter(fn (Category $category) => $category->products->isNotEmpty())
            ->values();
    }

    public function findVisibleProduct(int $productId, StoreContext $store): ?Product
    {
        $product = Product::query()->where('status', 'active')->with('category')->find($productId);

        if (! $product || ! $this->isVisible($product, $store)) {
            return null;
        }

        return $product;
    }

    /**
     * قیمت نمایشی یک محصول در این Context — دقیقاً همان متدهای موجود
     * روی Product، بدون هیچ محاسبه‌ی اضافه (بند ۱ Roadmap:
     * «هیچ Price محاسبه‌شده در Client»).
     */
    public function displayPrice(Product $product, StoreContext $store): float
    {
        return $store->isReseller()
            ? (float) $product->customersPrice($store->reseller)
            : $product->mainPrice();
    }

    protected function isVisible(Product $product, StoreContext $store): bool
    {
        if ($store->isMain()) {
            return true;
        }

        return $this->pricing->isSellable($store->reseller, $product);
    }
}
