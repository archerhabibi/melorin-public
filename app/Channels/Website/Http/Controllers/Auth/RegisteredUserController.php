<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\RegisterRequest;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * فاز W1 بند ۱: Register (Breeze پایه). طبق تصمیم ۹.۲ (Session-based،
 * نه Token)، بعد از ساخت User بلافاصله Auth::login می‌کنیم — هیچ
 * توکن جداگانه‌ای برنمی‌گردانیم.
 *
 * توجه معماری: ساختن یک User اینجا «منطق کسب‌وکار Core» محسوب نمی‌شود
 * (بند ۹۳) — این صرفاً Identity/Auth است، نه خرید/کیف‌پول/Provisioning.
 * تنها بخشی که واقعاً به Core تعلق دارد (CustomerAccount هر Context)
 * توسط IdentityService و از طریق میان‌افزار EnsureCustomerAccountResolved
 * در همان اولین درخواست احراز‌هویت‌شده‌ی بعدی resolve می‌شود؛ اینجا
 * تکرارش نمی‌کنیم.
 */
class RegisteredUserController
{
    use ResolvesWebsiteRouteNames;

    public function create(): View
    {
        return view('website.auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'active',
            'joined_from' => 'website',
        ]);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->intended($this->websiteRoute($request, 'home'));
    }
}
