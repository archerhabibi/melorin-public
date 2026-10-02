<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Http\Requests\Auth\RegisterRequest;
use App\Channels\Website\Support\GuestCheckoutContinuation;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Register (Breeze پایه). طبق تصمیم ۹.۲ (Session-based،
 * نه Token)، بعد از ساخت User بلافاصله Auth::login می‌کنیم — هیچ
 * توکن جداگانه‌ای برنمی‌گردانیم.
 *
 * توجه معماری: ساختن یک User اینجا «منطق کسب‌وکار Core» محسوب نمی‌شود
 * (بند ۹۳) — این صرفاً Identity/Auth است، نه خرید/کیف‌پول/Provisioning.
 * تنها بخشی که واقعاً به Core تعلق دارد (CustomerAccount هر Context)
 * توسط IdentityService و فقط در لحظه‌ی یک خرید واقعی ساخته می‌شود
 * (CheckoutController::store)؛ اینجا تکرارش نمی‌کنیم.
 *
 * Referral: `?ref={user_id}` روی صفحه‌ی ثبت‌نام در Session نگه داشته می‌شود (چون
 * ثبت‌نام یک فرم دو-مرحله‌ای HTTP است: GET صفحه، بعد POST جدا) و در
 * لحظه‌ی ساخت User واقعی، فقط اگر به یک User موجود اشاره کند مصرف
 * می‌شود — عدد نامعتبر بی‌صدا نادیده گرفته می‌شود، نه خطا (تجربه‌ی
 * ثبت‌نام کسی نباید به‌خاطر یک لینک معرفی خراب بشکند).
 *
 * Guest/Verification (Master 2.7 G6، G7، G11):
 *  - اگر نشست Guest فعال باشد، فرم با email/name/phone آن پیش‌پر می‌شود
 *    (فقط برای راحتی؛ Proof نیست — R9).
 *  - بعد از ثبت‌نام Email Verification ارسال می‌شود و کاربر به صفحه‌ی
 *    «تأیید Email» می‌رود؛ خرید Pending پس از تأیید (url.intended) ادامه
 *    می‌یابد. هیچ User/CustomerAccount/Purchase ای از Guest ساخته نمی‌شود.
 */
class RegisteredUserController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(protected GuestCheckoutContinuation $guestContinuation) {}

    public function create(Request $request, StoreContext $store): View
    {
        $ref = $request->integer('ref');

        if ($ref && User::query()->whereKey($ref)->exists()) {
            $request->session()->put('referrer_id_candidate', $ref);
        }

        $guest = $this->guestContinuation->activeFor($request, $store);

        return view('website.auth.register', [
            'guestPrefill' => $guest ? [
                'email' => $guest->guest_email,
                'name' => $guest->guest_name,
                'phone' => $guest->guest_phone,
            ] : [],
        ]);
    }

    public function store(RegisterRequest $request, StoreContext $store): RedirectResponse
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

        // G11: ارسال لینک تأیید Email. شکست ارسال (Mail/Queue) نباید ثبت‌نام
        // را بشکند؛ کاربر می‌تواند از صفحه‌ی تأیید «ارسال مجدد» بزند.
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::warning('website_verification_email_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        // G6: خرید Pending بعد از تأیید Email ادامه می‌یابد.
        $checkoutUrl = $this->guestContinuation->checkoutUrl($request, $store);

        if ($checkoutUrl) {
            $request->session()->put('url.intended', $checkoutUrl);
        }

        return redirect()->route('verification.notice');
    }
}
