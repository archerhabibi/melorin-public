<?php

namespace App\Channels\Website\Http\Middleware;

use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * هر مسیر Auth باید CustomerAccount/StoreContext درست را resolve کند (نه
 * User مستقل).
 *
 * قاعده‌ی مالک پروژه: «تا خرید انجام نشود نباید CustomerAccount جدید
 * ساخته شود». اگر این میان‌افزار روی هر GET عضویت می‌ساخت، صرفِ باز کردن
 * `/store/{slug}/orders` (حتی از یک <img> مخفی در سایت دیگر، چون GET
 * CSRF Token لازم ندارد) کاربر را بدون اطلاعش «مشتریِ» آن نماینده ثبت
 * می‌کرد. پس این میان‌افزار فقط **می‌خواند** (`findCustomerAccount`،
 * nullable) و هرگز نمی‌سازد.
 *
 * (تنها استثنا: ورود موفق با Google یا Email+Password، G21، که در Core عضویت را می‌سازد؛ نه اینجا.)
 *
 * ساختنِ واقعی فقط در لحظه‌ی یک اقدام مالی واقعی (POST) است:
 * `CheckoutController::store` (خرید) و `AccountsController::renew`
 * (تمدید) — هرکدام صریحاً `IdentityService::resolveCustomerAccount()`
 * را خودشان صدا می‌زنند.
 *
 * صفحات فقط-خواندنی (Orders، Accounts، Referral) با الگوی null-safe
 * (`$customer?->id`، `! $customer`) برای کاربرِ بدون عضویت «هیچ‌چیز» را
 * درست نشان می‌دهند.
 */
class EnsureCustomerAccountResolved
{
    public function __construct(protected IdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $store = app(StoreContext::class);
            $customerAccount = $this->identity->findCustomerAccount($user, $store);

            $request->attributes->set('customerAccount', $customerAccount);
            view()->share('customerAccount', $customerAccount);
        }

        return $next($request);
    }
}
