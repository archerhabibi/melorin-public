<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Home + لیست Product — از Core، بدون هیچ Price محاسبه‌شده در Client».
 * B4.1: جست‌وجو/فیلتر سبد/مرتب‌سازی/فقط‌موجودها با query string (GET، بدون نوشتن). پاک‌سازی ورودی و همه‌ی قواعد در Core
 * است (`CatalogQuery`, `ProductCatalogService`)؛ این Controller فقط تحویل می‌دهد. قرارداد: `CUSTOMER-CATALOG-CONTRACT.md`.
 */
class HomeController
{
    public function __construct(protected WebsiteCatalogFacade $catalog) {}

    public function index(Request $request, StoreContext $store): View
    {
        return view('website.shared.home', [
            'catalog' => $this->catalog->catalog($store, $request->query()),
            'store' => $store,
            // نام‌های نسبی Route (Main و فروشگاه نماینده)؛ View هیچ شاخه‌ی isReseller برای لینک ندارد.
            'route' => fn (string $name, array $params = []) => $store->isReseller()
                ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
                : route('website.'.$name, $params),
        ]);
    }
}
