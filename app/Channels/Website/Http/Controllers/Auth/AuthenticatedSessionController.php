<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\LoginRequest;
use App\Channels\Website\Support\GuestCheckoutContinuation;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Identity\LoginMembershipService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * تصمیم ۹.۲ (Session-based / Cookie، نه Token).
 *
 * G21: ورود موفق ⇒ CustomerAccount فروشگاه مبدأ (استثنای R7؛ هم‌تراز Google).
 */
class AuthenticatedSessionController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected GuestCheckoutContinuation $guestContinuation,
        protected LoginMembershipService $membership,
    ) {}

    public function create(Request $request, StoreContext $store): View
    {
        // B2.3: اگر نشست Guest فعال است، صفحه یادآور می‌شود که همین خرید ادامه پیدا می‌کند.
        return view('website.auth.login', [
            'guestPending' => $this->guestContinuation->activeFor($request, $store)?->loadMissing('product'),
        ]);
    }

    public function store(LoginRequest $request, StoreContext $store): RedirectResponse
    {
        // G6: اگر نشست Guest فعال است و مقصد دیگری (url.intended) در
        // کار نیست، Login همان خرید Pending را ادامه می‌دهد. Login خودکار /
        // ساخت User در هیچ مسیری وجود ندارد؛ فقط credential واقعی.
        $guestCheckoutUrl = $request->session()->has('url.intended')
            ? null
            : $this->guestContinuation->checkoutUrl($request, $store);

        $request->authenticate();

        $request->session()->regenerate();

        // G21: ورود موفق با Email+Password هم مثل Google، CustomerAccount فروشگاه مبدأ
        // (Context همین درخواست) را Resolve/می‌سازد. ورودِ ردشده تا اینجا نمی‌رسد.
        $this->membership->ensure($request->user(), $store, 'password');

        return redirect()->intended($guestCheckoutUrl ?? $this->websiteRoute($request, 'home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($this->websiteRoute($request, 'home'));
    }
}
