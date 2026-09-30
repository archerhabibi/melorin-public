<?php

namespace App\Channels\Website\Http\Controllers\Guest;

use App\Channels\Website\Services\WebsiteCatalogFacade;
use App\Channels\Website\Services\WebsiteGuestCheckoutFacade;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W3 بند ۱ — Guest Checkout Token: بدون نیاز به login یا
 * CustomerAccount برای شروع خرید (بند ۷ زیرسند). این کنترلر عمداً
 * پشت `auth` نیست — دقیقاً برعکس CheckoutController.
 *
 * مسیر پرداخت واقعی (بند ۳) در GuestPurchaseController همین پوشه است،
 * نه اینجا — جداسازی عمدی: این کنترلر فقط «شروع نشست» است.
 *
 * مالکیت: طبق بخش ۱۰ Roadmap («تقسیم کار بین ۵ نفر»)، این پوشه
 * (`Controllers/Guest/`) و `views/website/guest/` انحصاراً مال نفر ۱
 * است. پچ 3.2.4 این فایل را از `Controllers/Shared/` به اینجا منتقل
 * کرد (بدون تغییر رفتار) تا مرز مالکیت برای Merge نهایی روشن باشد.
 *
 * نام Cookie عمداً بدون پیشوند Context (main/reseller) است چون هر
 * توکن خودش reseller_id دارد و findActive() آن را با Context درخواست
 * تطبیق می‌دهد.
 */
class GuestCheckoutController
{
    use ResolvesWebsiteRouteNames;

    public const COOKIE_NAME = 'guest_checkout_token';

    protected const COOKIE_MINUTES = 45;

    public function __construct(
        protected WebsiteCatalogFacade $catalog,
        protected WebsiteGuestCheckoutFacade $guest,
    ) {}

    public function show(int $product, StoreContext $store, Request $request): View|Response
    {
        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        return view('website.guest.checkout-start', [
            'product' => $productModel,
            'price' => $this->catalog->displayPrice($productModel, $store),
            'store' => $store,
        ]);
    }

    public function store(Request $request, int $product, StoreContext $store): RedirectResponse
    {
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:100'],
            'guest_phone' => ['required', 'string', 'max:32'],
            'guest_email' => ['nullable', 'email', 'max:190'],
        ]);

        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        $guestCheckout = $this->guest->start(
            product: $productModel,
            store: $store,
            name: $data['guest_name'],
            phone: $data['guest_phone'],
            email: $data['guest_email'] ?? null,
        );

        // Cookie از طریق EncryptCookies middleware (گروه web) به‌صورت
        // خودکار رمزنگاری و امضا می‌شود — دقیقاً همان «Cookie امضاشده»ی
        // بند ۹.۳، بدون نیاز به پیاده‌سازی دستی HMAC.
        return redirect()
            ->to($this->websiteRoute($request, 'guest-checkout.pending'))
            ->cookie(self::COOKIE_NAME, $guestCheckout->token, self::COOKIE_MINUTES);
    }

    public function pending(Request $request, StoreContext $store): View|Response
    {
        $token = $request->cookie(self::COOKIE_NAME);
        $guestCheckout = $token ? $this->guest->findActive($token, $store) : null;

        if (! $guestCheckout) {
            abort(404, 'نشست خرید مهمان پیدا نشد یا منقضی شده است.');
        }

        $guestCheckout->load('product');

        return view('website.guest.checkout-pending', [
            'guestCheckout' => $guestCheckout,
            'store' => $store,
        ]);
    }
}
