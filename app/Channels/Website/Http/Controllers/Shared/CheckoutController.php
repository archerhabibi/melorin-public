<?php

namespace App\Channels\Website\Http\Controllers\Shared;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Channels\Website\Services\WebsitePurchaseFacade;
use App\Channels\Website\Services\WebsiteWalletFacade;
use App\Channels\Website\Support\CoreErrorMapper;
use App\Models\CustomerAccount;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * فاز W2 بند ۳ و ۵: «Checkout مستقیم بدون Cart» — `Product → Checkout`،
 * فقط پرداخت کیف‌پول (Wallet Payment، بند ۲۵). Zarinpal (بند ۲۶) و
 * Card-to-Card (بند ۲۷) عمداً در همین پچ نیستند: این کنترلر طبق بند ۱
 * Roadmap («هیچ Business Logic مستقلی») فقط یک مسیر پرداخت را
 * پیاده‌سازی می‌کند تا اضافه‌شدن درگاه‌های بعدی به شکلِ افزودن یک
 * حالت جدید به همین الگو باشد، نه بازنویسی — نه اینکه سه درگاه نصفه و
 * تست‌نشده با هم اضافه شوند (همان درسی که در بند ۷ فاز W7 روی
 * Verification Matrix تاکید شده).
 *
 * Guest Checkout (فاز W3) از این کنترلر استفاده نمی‌کند؛ این کنترلر
 * فقط پشت `auth` + `store.customer` سوار است.
 */
class CheckoutController
{
    public function __construct(
        protected WebsiteCatalogFacade $catalog,
        protected WebsiteWalletFacade $wallet,
        protected WebsitePurchaseFacade $purchase,
        protected CoreErrorMapper $errors,
    ) {}

    /**
     * صفحه‌ی تایید خرید. توکن Idempotency اینجا (نه در store()) ساخته
     * و در فرم به‌صورت hidden قرار می‌گیرد — بند ۷ فاز W2: «از الگوی
     * idempotencyKey که در Bot/Core از قبل هست استفاده شود». هر بار
     * تازه‌سازی این صفحه یک توکن جدید می‌سازد (الگوی استاندارد
     * Synchronizer Token)، پس Submit دوبار همان فرم (مثلاً با دکمه‌ی
     * Back مرورگر) دقیقاً همان Operation قبلی را idempotent برمی‌گرداند
     * و خرید دوم واقعی رخ نمی‌دهد.
     */
    public function show(int $product, StoreContext $store): View|Response
    {
        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        return view('website.shared.checkout-show', [
            'product' => $productModel,
            'price' => $this->catalog->displayPrice($productModel, $store),
            'balance' => $this->wallet->balance(auth()->user(), $store),
            'store' => $store,
            'idempotencyToken' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, int $product, StoreContext $store): RedirectResponse
    {
        $request->validate([
            'idempotency_token' => ['required', 'string', 'max:64'],
        ]);

        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        /** @var CustomerAccount $customer */
        $customer = $request->attributes->get('customerAccount');

        try {
            $account = $this->purchase->purchaseWithWallet(
                customer: $customer,
                product: $productModel,
                store: $store,
                idempotencyKey: 'website:'.$request->string('idempotency_token'),
            );
        } catch (Throwable $e) {
            $mapped = $this->errors->map($e);

            return back()->withErrors(['checkout' => $mapped['message'].' (کد پیگیری: '.$mapped['reference'].')']);
        }

        $routeName = $store->isReseller() ? 'website.store.orders.show' : 'website.orders.show';
        $routeParams = $store->isReseller()
            ? ['slug' => $store->reseller->slug, 'order' => $account->order_id]
            : ['order' => $account->order_id];

        return redirect()->route($routeName, $routeParams)
            ->with('status', 'خرید با موفقیت ثبت شد.');
    }
}
