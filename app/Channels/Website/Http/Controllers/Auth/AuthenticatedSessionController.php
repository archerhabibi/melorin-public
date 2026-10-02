<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\LoginRequest;
use App\Channels\Website\Support\GuestCheckoutContinuation;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * تصمیم ۹.۲ (Session-based / Cookie، نه Token).
 */
class AuthenticatedSessionController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(protected GuestCheckoutContinuation $guestContinuation) {}

    public function create(): View
    {
        return view('website.auth.login');
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
