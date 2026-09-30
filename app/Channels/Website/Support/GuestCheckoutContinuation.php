<?php

namespace App\Channels\Website\Support;

use App\Channels\Website\Http\Controllers\Guest\GuestCheckoutController;
use App\Channels\Website\Services\WebsiteGuestCheckoutFacade;
use App\Models\GuestCheckout;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;

/**
 * Master 2.7 G6 (شکاف C3) — Login/Register باید «همان خرید» Pending را ادامه دهد.
 *
 * این کلاس منطق کسب‌وکار ندارد؛ فقط از روی Cookie نشست فعالِ همین
 * StoreContext را پیدا می‌کند و آدرس Checkout همان Product را می‌دهد.
 * هیچ User/CustomerAccount/Purchase ای نمی‌سازد؛ خرید واقعی همچنان با
 * CheckoutController (Lazy CustomerAccount، Idempotency Token) انجام می‌شود.
 */
class GuestCheckoutContinuation
{
    use ResolvesWebsiteRouteNames;

    public function __construct(protected WebsiteGuestCheckoutFacade $guest) {}

    public function activeFor(Request $request, StoreContext $store): ?GuestCheckout
    {
        $token = $request->cookie(GuestCheckoutController::COOKIE_NAME);

        return $token ? $this->guest->findActive((string) $token, $store) : null;
    }

    /** آدرس Checkout همان خرید Pending، یا null اگر نشست فعالی نیست. */
    public function checkoutUrl(Request $request, StoreContext $store): ?string
    {
        $guest = $this->activeFor($request, $store);

        return $guest
            ? $this->websiteRoute($request, 'checkout.show', ['product' => $guest->product_id])
            : null;
    }
}
