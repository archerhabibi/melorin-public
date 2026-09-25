<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\LoginRequest;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * فاز W1 بند ۱ + تصمیم ۹.۲ (Session-based / Cookie، نه Token).
 */
class AuthenticatedSessionController
{
    use ResolvesWebsiteRouteNames;

    public function create(): View
    {
        return view('website.auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended($this->websiteRoute($request, 'home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($this->websiteRoute($request, 'home'));
    }
}
