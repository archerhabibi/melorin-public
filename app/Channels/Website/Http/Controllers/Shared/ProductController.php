<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Services\Core\Store\StoreContext;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W2 بند ۲: «Product Detail — Price دقیقاً از PriceSnapshot Contract
 * Core». محصولی که در این Context قابل‌فروش/قابل‌مشاهده نیست، دقیقاً
 * مثل یک محصول ناموجود 404 می‌شود — نه پیام خطای جداگانه، تا وجود/عدم‌
 * وجودِ محصولات غیرفعالِ نماینده از بیرون قابل‌حدس‌زدن نباشد.
 */
class ProductController
{
    public function __construct(protected WebsiteCatalogFacade $catalog) {}

    public function show(int $product, StoreContext $store): View|Response
    {
        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        return view('website.shared.product-show', [
            'product' => $productModel,
            'price' => $this->catalog->displayPrice($productModel, $store),
            'store' => $store,
        ]);
    }
}
