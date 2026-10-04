<?php

namespace App\Services\Core\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ResellerProductPrice;
use App\Services\Core\Store\StoreContext;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Support\Collection;

/**
 * B4.1 — Product Catalog: «چه چیزی، با چه قیمتی، در این فروشگاه دیده می‌شود» برای همه‌ی Channelها.
 *
 * قواعد (قرارداد: `CUSTOMER-CATALOG-CONTRACT.md`):
 *  - **فقط خواندن و فقط نمایش.** هیچ‌چیز نمی‌نویسد و تصمیم خرید نمی‌گیرد؛ مرجع خرید همچنان `PurchaseGuard` /
 *    `ResellerPricingService::assertSellable` است. این سرویس عمداً همان‌ها را **دوباره پیاده نمی‌کند**:
 *    فروشگاه نماینده روی `sellableProducts` (همان مدل opt-in که ربات نماینده استفاده می‌کند) و `isSellable` سوار است
 *    و یک تست Parity تضمین می‌کند فهرست با `isSellable` هیچ‌وقت از هم جدا نشود.
 *  - **قیمت نهایی از Core:** Main = `main_price`، نماینده = `customers_price` (هرگز `reseller_price`).
 *  - **کم‌پرس‌وجو:** فهرست با تعداد ثابتی Query ساخته می‌شود (بدون N+1 قیمت/سبد).
 *  - پیدا نشدن = نامرئی بودن: محصولی که در این Context دیده نمی‌شود با محصول ناموجود فرقی ندارد (404/«دیگر در دسترس نیست»).
 */
class ProductCatalogService
{
    public function __construct(protected ResellerPricingService $pricing) {}

    public function catalog(StoreContext $store, ?CatalogQuery $query = null): Catalog
    {
        $query ??= new CatalogQuery;
        $all = $this->visibleItems($store);

        $options = $all->groupBy(fn (CatalogItem $i) => $i->category->id)
            ->map(fn (Collection $group) => [
                'id' => (int) $group->first()->category->id,
                'name' => (string) $group->first()->category->name,
                'count' => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $matched = $this->sorted($this->filtered($all, $query), $query->sort);

        $categories = $matched->groupBy(fn (CatalogItem $i) => $i->category->id)
            ->sortKeys()
            ->map(fn (Collection $items) => new CatalogCategory($items->first()->category, $items->values()))
            ->values();

        return new Catalog($query, $matched->values(), $categories, $options, $all->count(), $matched->count());
    }

    /** null = در این Context قابل‌مشاهده نیست (غیرفعال، سبد بسته، قیمت‌گذاری/فعال‌نشده، نمایندگی غیرفعال، ...) */
    public function find(int $productId, StoreContext $store): ?CatalogItem
    {
        if (! $store->isOperational()) {
            return null;
        }

        $product = Product::query()->with('category')->find($productId);

        if (! $product || $product->status !== 'active' || ! $product->category || $product->category->status !== 'active') {
            return null;
        }

        if ($store->isMain()) {
            return new CatalogItem($product, $product->category, $product->mainPrice());
        }

        // مرجع واحد: همان Guard که خرید هم با آن چک می‌شود.
        if (! $this->pricing->isSellable($store->reseller, $product)) {
            return null;
        }

        $price = $product->customersPrice($store->reseller);

        return $price === null ? null : new CatalogItem($product, $product->category, $price);
    }

    /**
     * تعرفه‌های جایگزین هم‌سبد (موجود، نزدیک‌ترین قیمت) — برای صفحه‌ی تعرفه‌ی «ظرفیت تکمیل» و پیشنهاد کنار هر تعرفه.
     *
     * @return Collection<int, CatalogItem>
     */
    public function alternatives(CatalogItem $item, StoreContext $store, int $limit = 3): Collection
    {
        return $this->visibleItems($store)
            ->filter(fn (CatalogItem $i) => $i->category->id === $item->category->id && $i->id() !== $item->id() && ! $i->isSoldOut())
            ->sortBy(fn (CatalogItem $i) => [abs($i->price - $item->price), $i->id()])
            ->take(max(0, $limit))
            ->values();
    }

    // ───────────────────────── داخلی ─────────────────────────

    /** @return Collection<int, CatalogItem> ترتیب پایه: شناسه‌ی سبد، سپس شناسه‌ی تعرفه */
    protected function visibleItems(StoreContext $store): Collection
    {
        if (! $store->isOperational()) {
            return collect();
        }

        if ($store->isMain()) {
            $products = Product::query()
                ->where('status', 'active')
                ->whereHas('category', fn ($q) => $q->where('status', 'active'))
                ->with('category')
                ->get();

            return $this->base($products->map(fn (Product $p) => new CatalogItem($p, $p->category, $p->mainPrice())));
        }

        $reseller = $store->reseller;
        $products = $this->pricing->sellableProducts($reseller)->load('category')
            ->filter(fn (Product $p) => $p->category instanceof Category && $p->category->status === 'active')
            ->values();

        $prices = ResellerProductPrice::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_enabled', true)
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('customers_price', 'product_id');

        return $this->base(
            $products
                ->filter(fn (Product $p) => $prices->has($p->id))
                ->map(fn (Product $p) => new CatalogItem($p, $p->category, (int) $prices[$p->id]))
        );
    }

    /** @param  Collection<int, CatalogItem>  $items */
    protected function base(Collection $items): Collection
    {
        return $items->sortBy(fn (CatalogItem $i) => [$i->category->id, $i->id()])->values();
    }

    /**
     * @param  Collection<int, CatalogItem>  $items
     * @return Collection<int, CatalogItem>
     */
    protected function filtered(Collection $items, CatalogQuery $query): Collection
    {
        if ($query->categoryId !== null) {
            $items = $items->filter(fn (CatalogItem $i) => (int) $i->category->id === $query->categoryId);
        }

        if ($query->search !== null) {
            $items = $items->filter(fn (CatalogItem $i) => CatalogText::matches($i->product->name.' '.$i->category->name, $query->search));
        }

        if ($query->availableOnly) {
            $items = $items->reject(fn (CatalogItem $i) => $i->isSoldOut());
        }

        return $items->values();
    }

    /**
     * ظرفیت‌تکمیل‌ها همیشه آخر هر فهرست؛ سپس کلید مرتب‌سازی؛ سپس شناسه (پایدار).
     *
     * @param  Collection<int, CatalogItem>  $items
     * @return Collection<int, CatalogItem>
     */
    protected function sorted(Collection $items, string $sort): Collection
    {
        return $items->sortBy(function (CatalogItem $i) use ($sort) {
            $key = match ($sort) {
                CatalogQuery::SORT_PRICE_ASC => $i->price,
                CatalogQuery::SORT_PRICE_DESC => -$i->price,
                CatalogQuery::SORT_DURATION => -(int) $i->product->duration_days,
                // حجم نامحدود بیشترین است
                CatalogQuery::SORT_TRAFFIC => $i->isUnlimitedTraffic() ? -PHP_FLOAT_MAX : -(float) $i->product->traffic_gb,
                default => 0,
            };

            // در حالت پیش‌فرض ترتیب پایه (سبد، شناسه) حفظ می‌شود؛ ظرفیت‌تکمیل فقط در همان سبد عقب می‌رود.
            return $sort === CatalogQuery::SORT_DEFAULT
                ? [$i->category->id, $i->isSoldOut() ? 1 : 0, $i->id()]
                : [$i->isSoldOut() ? 1 : 0, $key, $i->id()];
        })->values();
    }
}
