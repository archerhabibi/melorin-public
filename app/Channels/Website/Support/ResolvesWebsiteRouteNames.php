<?php

namespace App\Channels\Website\Support;

use Illuminate\Http\Request;

/**
 * بند ۲ Roadmap («Middleware مشترک ResolveStoreContext به‌جای تکرار
 * منطق تشخیص Reseller در هر Controller») همان اصل را اینجا هم ادامه
 * می‌دهد: routes/website.php دو نسخه (website.* و website.store.*) از
 * هر route اسم‌گذاری می‌کند، پس هر Controller که نیاز به redirect یا
 * route() دارد باید بداند «الان کدام نسخه». به‌جای تکرار
 * `$request->route('slug') ? ... : ...` در هر Controller، همین یک‌بار
 * اینجا نوشته شده.
 */
trait ResolvesWebsiteRouteNames
{
    protected function websiteRoute(Request $request, string $name, array $params = []): string
    {
        $slug = $request->route('slug');

        if ($slug) {
            return route('website.store.'.$name, ['slug' => $slug, ...$params]);
        }

        return route('website.'.$name, $params);
    }
}
