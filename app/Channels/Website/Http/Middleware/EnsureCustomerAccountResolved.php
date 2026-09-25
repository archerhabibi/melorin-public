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
 * این میان‌افزار *بعد* از auth و *بعد* از store.context روی route قرار
 * می‌گیرد. کاری که می‌کند این نیست که خودش تصمیم مالی بگیرد — فقط از
 * IdentityService (تنها نقطه‌ی مجاز طبق بند ۳۶ سند مادر) می‌خواهد
 * CustomerAccount همین User در همین StoreContext را resolve/بسازد، و
 * آن را روی Request قابل‌دسترس می‌کند تا Controllerها مجبور نباشند در
 * هر متد دوباره صدایش بزنند.
 */
class EnsureCustomerAccountResolved
{
    public function __construct(protected IdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $store = app(StoreContext::class);
            $customerAccount = $this->identity->resolveCustomerAccount($user, $store);

            $request->attributes->set('customerAccount', $customerAccount);
            view()->share('customerAccount', $customerAccount);
        }

        return $next($request);
    }
}
