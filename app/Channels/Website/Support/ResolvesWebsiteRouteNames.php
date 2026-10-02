<?php

namespace App\Channels\Website\Support;

use Illuminate\Http\Request;

/**
 * همان اصلِ «Middleware مشترک ResolveStoreContext به‌جای تکرار منطق
 * تشخیص Reseller در هر Controller» را اینجا هم ادامه می‌دهد:
 * routes/website.php دو نسخه (website.* و website.store.*) از
 * هر route اسم‌گذاری می‌کند، پس هر Controller که نیاز به redirect یا
 * route() دارد باید بداند «الان کدام نسخه». به‌جای تکرار
 * `$request->route('slug') ? ... : ...` در هر Controller، همین یک‌بار
 * اینجا نوشته شده.
 */
trait ResolvesWebsiteRouteNames
{
    protected function websiteRoute(Request $request, string $name, array $params = []): string
    {
        // slug بعد از ResolveStoreContext از route حذف می‌شود؛ منبع حقیقت
        // StoreContext است.
        $store = app(\App\Services\Core\Store\StoreContext::class);

        if ($store->isReseller()) {
            return route('website.store.'.$name, ['slug' => $store->reseller->slug, ...$params]);
        }

        return route('website.'.$name, $params);
    }
}
