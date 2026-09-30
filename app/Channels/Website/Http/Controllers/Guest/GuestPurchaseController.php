<?php

namespace App\Channels\Website\Http\Controllers\Guest;

use App\Channels\Website\Services\WebsiteGuestCheckoutFacade;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W3 بند ۳ — «Flow کامل: Guest → Product → Checkout → Payment →
 * Order → Provisioning». مرجع کامل تصمیم:
 * docs/PHASE-W3-PART2-GUEST-PURCHASE.md.
 *
 * تصمیم معماری این پچ (خلاصه؛ توضیح کامل در مستند بالا): به‌جای ساختن
 * یک مسیر خرید موازی برای مهمان، همین‌جا یک User «ناقص» (بند
 * Identity Completeness سند مادر — فقط تلفن/نام، بدون رمز عبور) ساخته
 * می‌شود و بلافاصله Auth::login می‌شود؛ از همان لحظه، بقیه‌ی مسیر
 * دقیقاً همان CheckoutController/ChargeController تست‌شده‌ی پچ‌های
 * ۳.۲.۱/۳.۲.۲ است — نه یک پیاده‌سازی موازی.
 *
 * حیاتی (بند ۸۷ سند مادر: «merge خودکار بدون اثبات ممنوع»): اگر تلفن/
 * ایمیل مهمان از قبل برای یک User واقعی ثبت شده، **هرگز** به آن
 * Identity متصل نمی‌شویم — فقط او را به Login هدایت می‌کنیم. تشخیص
 * مالکیت واقعی آن شماره از عهده‌ی این فرم خارج است.
 */
class GuestPurchaseController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected WebsiteGuestCheckoutFacade $guest,
        protected IdentityService $identity,
        protected AuditService $audit,
    ) {}

    public function store(Request $request, StoreContext $store): View|RedirectResponse|Response
    {
        $token = $request->cookie(GuestCheckoutController::COOKIE_NAME);
        $guestCheckout = $token ? $this->guest->findActive($token, $store) : null;

        if (! $guestCheckout) {
            abort(404, 'نشست خرید مهمان پیدا نشد یا منقضی شده است.');
        }

        // بند ۸۷: تطبیق صرفِ ادعایی، نه اثبات‌شده — پس هرگز به Identity
        // موجود وصل نمی‌شویم، فقط او را به ورود هدایت می‌کنیم.
        $existing = $this->identity->findIdentity(
            email: $guestCheckout->guest_email,
            phone: $guestCheckout->guest_phone,
        );

        if ($existing) {
            $intendedUrl = $this->websiteRoute($request, 'checkout.show', ['product' => $guestCheckout->product_id]);
            $request->session()->put('url.intended', $intendedUrl);

            // بند ۴ فاز W6 — این یک رویداد امنیتی‌واقعی است (تلاش برای
            // خرید با شماره/ایمیل متعلق به کسی دیگر، ولو ناخواسته)، پس
            // Audit می‌شود حتی قبل از هر Auth ای. actor اینجا 'system'
            // می‌ماند چون بازدیدکننده هنوز هیچ هویت احراز‌شده‌ای ندارد —
            // AuditService هیچ actor_type ای برای «ناشناس» ندارد؛ این
            // یک محدودیت شناخته‌شده است، نه غفلت (مستند شده در
            // docs/PHASE-W6-PART4-AUDIT.md).
            $this->audit->record(
                'identity.guest_collision_detected',
                $existing,
            );

            return redirect()
                ->to($this->websiteRoute($request, 'login'))
                ->with('status', 'این شماره یا ایمیل قبلاً ثبت شده. برای تکمیل خرید وارد شوید.');
        }

        try {
            $user = User::create([
                'full_name' => $guestCheckout->guest_name,
                'phone' => $guestCheckout->guest_phone,
                'email' => $guestCheckout->guest_email,
                'password' => null,
                'status' => 'active',
                'joined_from' => 'website_guest_checkout',
            ]);
        } catch (QueryException) {
            // تصادم unique (بند مشابه resolveCustomerAccount در
            // IdentityService): یک درخواست هم‌زمان همین شماره را زودتر
            // به یک User واقعی تبدیل کرده. همان مسیر «هدایت به ورود»
            // را برمی‌گردانیم، نه خطای خام.
            return redirect()
                ->to($this->websiteRoute($request, 'login'))
                ->with('status', 'این شماره یا ایمیل هم‌اکنون ثبت شد. برای تکمیل خرید وارد شوید.');
        }

        $guestCheckout->update(['status' => 'consumed']);

        // بند ۴ فاز W6 — ساختن یک هویت جدید از خرید مهمان خودش یک
        // عملیات حساس شناسایی/Identity است.
        $this->audit->record(
            'identity.guest_account_created',
            $user,
            after: ['phone' => $user->phone, 'has_email' => (bool) $user->email],
            actor: $user,
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->to(
            $this->websiteRoute($request, 'checkout.show', ['product' => $guestCheckout->product_id])
        );
    }
}
