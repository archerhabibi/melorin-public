<?php

namespace App\Channels\Website\Http\Middleware;

use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W1 بند ۵ Roadmap: «هر مسیر Auth باید CustomerAccount/StoreContext
 * درستی را resolve کند (نه User مستقل — بند ۵، ۱۰۳.۶ سند مادر)».
 *
 * پچ ۳.۲.۱۷ — تصمیم صریح صاحب پروژه (override تحلیل قبلی نفر ۳ در
 * docs/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md): «تا خرید انجام
 * نشود نباید CustomerAccount جدید بسازد». پیش از این پچ، این میان‌افزار
 * با `resolveCustomerAccount()` روی **هر** درخواست GET زیر گروه
 * auth+store.customer یک CustomerAccount تازه می‌ساخت — یعنی صرفِ باز
 * کردن `/store/{slug}/orders` (حتی از طریق یک <img> مخفی در یک سایت
 * دیگر، چون GET هیچ CSRF Token ای لازم ندارد) کافی بود تا کاربر بدون
 * اطلاع خودش «مشتریِ» آن نماینده ثبت شود.
 *
 * از این پچ به بعد، این میان‌افزار فقط **می‌خواند** (`findCustomerAccount`،
 * nullable) — هرگز نمی‌سازد. ساختنِ واقعی فقط در لحظه‌ی یک اقدام مالی
 * واقعی (POST) اتفاق می‌افتد: `CheckoutController::store` (خرید) و
 * `AccountsController::renew` (تمدید) — هرکدام صریحاً
 * `IdentityService::resolveCustomerAccount()` را خودشان صدا می‌زنند،
 * نه این‌که به این میان‌افزار متکی باشند.
 *
 * صفحات فقط-خواندنی (Orders، Accounts، Referral) با همان الگوی
 * null-safe موجود (`$customer?->id`، `! $customer`) که از ابتدا در
 * آن‌ها بود، حالا واقعاً «هیچ‌چیز» را درست نشان می‌دهند (نه لیست خالی
 * از یک CustomerAccount خالی که همین الان ساخته شده).
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
