<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Services\Core\Store\StoreContext;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Product Detail — Price دقیقاً از PriceSnapshot Contract
 * Core». محصولی که در این Context قابل‌فروش/قابل‌مشاهده نیست، دقیقاً
 * مثل یک محصول ناموجود 404 می‌شود — نه پیام خطای جداگانه، تا وجود/عدم‌
 * وجودِ محصولات غیرفعالِ نماینده از بیرون قابل‌حدس‌زدن نباشد.
 *
 * B4.1: «ظرفیت تکمیل» محصول **دیده می‌شود** (404 نیست) ولی دکمه‌ی خرید ندارد و تعرفه‌های جایگزین پیشنهاد می‌شود.
 */
class ProductController
{
    public function __construct(protected WebsiteCatalogFacade $catalog) {}

    public function show(int $product, StoreContext $store): View|Response
    {
        $item = $this->catalog->item($product, $store);

        if (! $item) {
            abort(404);
        }

        return view('website.shared.product-show', [
            'item' => $item,
            'product' => $item->product,
            'price' => $item->price,
            'alternatives' => $item->isSoldOut() ? $this->catalog->alternatives($item, $store) : collect(),
            'store' => $store,
            // نام‌های نسبی Route (Main و فروشگاه نماینده)؛ View هیچ شاخه‌ی isReseller برای لینک ندارد.
            'route' => fn (string $name, array $params = []) => $store->isReseller()
                ? route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params])
                : route('website.'.$name, $params),
        ]);
    }
}
