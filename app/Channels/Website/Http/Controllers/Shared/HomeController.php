<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Services\Core\Store\StoreContext;
use Illuminate\View\View;

/**
 * فاز W2 بند ۱: «Home + لیست Product — از Core، بدون هیچ Price
 * محاسبه‌شده در Client». این Controller فقط WebsiteCatalogFacade را
 * صدا می‌زند و نتیجه را به View پاس می‌دهد — هیچ فیلتر/محاسبه‌ی
 * اضافه‌ای اینجا نیست.
 */
class HomeController
{
    public function __construct(protected WebsiteCatalogFacade $catalog) {}

    public function index(StoreContext $store): View
    {
        $categories = $this->catalog->categoriesWithProducts($store);

        return view('website.shared.home', [
            'categories' => $categories,
            'catalog' => $this->catalog,
            'store' => $store,
        ]);
    }
}
