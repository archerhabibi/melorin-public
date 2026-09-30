<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\RegisterRequest;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use Illuminate\Http\Request;
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
 *
 * پچ ۳.۲.۱۳ (نفر ۵ — بستنِ نکته‌ی بازِ Referral که در پچ ۳.۲.۱۲، نفر ۲،
 * صریح یادداشت شده بود: «ثبت‌نام سایت هنوز ?ref= را نمی‌خواند»):
 * `?ref={user_id}` روی صفحه‌ی ثبت‌نام در Session نگه داشته می‌شود (چون
 * ثبت‌نام یک فرم دو-مرحله‌ای HTTP است: GET صفحه، بعد POST جدا) و در
 * لحظه‌ی ساخت User واقعی، فقط اگر به یک User موجود اشاره کند مصرف
 * می‌شود — عدد نامعتبر بی‌صدا نادیده گرفته می‌شود، نه خطا (تجربه‌ی
 * ثبت‌نام کسی نباید به‌خاطر یک لینک معرفی خراب بشکند).
 */
class RegisteredUserController
{
    use ResolvesWebsiteRouteNames;

    public function create(Request $request): View
    {
        $ref = $request->integer('ref');

        if ($ref && User::query()->whereKey($ref)->exists()) {
            $request->session()->put('referrer_id_candidate', $ref);
        }

        return view('website.auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $referrerId = $request->session()->pull('referrer_id_candidate');

        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'active',
            'joined_from' => 'website',
            'referrer_id' => $referrerId,
        ]);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->intended($this->websiteRoute($request, 'home'));
    }
}
