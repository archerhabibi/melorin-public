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
 * Guest Checkout — Master 2.7 §3 / Website Contract §5.
 *
 * Guest فقط *شروع* Checkout است؛ خرید نهایی فقط بعد از Login/Register.
 * جریان: Product → فرم (email* · name · phone) → GuestCheckout(pending)
 * → Pending Page → Login/Register → ادامه‌ی همان خرید (Checkout).
 *
 * این کنترلر هیچ User/CustomerAccount/Wallet/Order نمی‌سازد (G3).
 * ادامه‌ی خرید بعد از Login/Register در Auth Controllerها با
 * GuestCheckoutContinuation انجام می‌شود (G6).
 *
 * نام Cookie عمداً بدون پیشوند Context است چون هر توکن reseller_id دارد و
 * findActive() آن را با Context درخواست تطبیق می‌دهد (G5).
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
        // G2: email تنها فیلد الزامی؛ name/phone اختیاری.
        $data = $request->validate([
            'guest_email' => ['required', 'string', 'email', 'max:190'],
            'guest_name' => ['nullable', 'string', 'max:100'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $productModel = $this->catalog->findVisibleProduct($product, $store);

        if (! $productModel) {
            abort(404);
        }

        $guestCheckout = $this->guest->start(
            product: $productModel,
            store: $store,
            email: $data['guest_email'],
            name: $data['guest_name'] ?? null,
            phone: $data['guest_phone'] ?? null,
        );

        // Cookie از طریق EncryptCookies (گروه web) رمزنگاری می‌شود.
        $cookie = cookie(self::COOKIE_NAME, $guestCheckout->token, self::COOKIE_MINUTES);

        // G7: تطابق با User موجود ⇒ هدایت به Login + Audit؛ هرگز Merge یا
        // Login خودکار. نشست Guest می‌ماند تا بعد از Login خرید ادامه یابد.
        if ($this->guest->detectCollision($guestCheckout)) {
            return redirect()
                ->to($this->websiteRoute($request, 'login'))
                ->with('status', 'برای ادامه‌ی این خرید، لطفاً وارد حساب خود شوید.')
                ->cookie($cookie);
        }

        return redirect()
            ->to($this->websiteRoute($request, 'guest-checkout.pending'))
            ->cookie($cookie);
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
