<?php

namespace App\Http\Middleware;

use App\Models\Reseller;
use App\Services\Resellers\Domains\ResellerDomainService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * B6.1 — مسیریابی بر پایه‌ی Host.
 *
 * وقتی Host درخواست یک دامنه‌ی اختصاصیِ **تأییدشده** باشد، مسیر درخواست (فقط داخلی) به
 * `/store/{slug}{path}` بازنویسی می‌شود تا همان Routeها، همان `ResolveStoreContext` و همان Controllerها
 * سرویس بدهند — هیچ منطق فروشگاهی تکرار یا دور زده نمی‌شود (Website = Channel). سمتِ خروجی،
 * URLهای ساخته‌شده با `route()` روی همان Host و **بدون** پیشوند `/store/{slug}` چاپ می‌شوند.
 *
 * رفتار:
 *  - Host خودِ پلتفرم، Hostِ ناشناخته، دامنه‌ی تأییدنشده یا قابلیت خاموش ⇒ دست‌نخورده (مثل قبل از B6.1).
 *  - نماینده‌ی غیرفعال ⇒ 404 (مثل `/store/{slug}`).
 *  - مسیرهای زیرساختی (`/up`، `/health`، `/payment`، `/build`، `/storage`) بازنویسی نمی‌شوند.
 *  - روی دامنه‌ی اختصاصی فقط مسیرهای فروشگاه سرو می‌شوند؛ `/store/x/...` و پنل Filament آنجا 404 است.
 *  - Cookie نشست همان Host است (`session.domain` = null)؛ نشست دامنه‌ی اختصاصی با پلتفرم مشترک نیست.
 *  - خطای دیتابیس هنگام تشخیص Host هرگز درخواست را نمی‌شکند (دست‌نخورده ادامه می‌یابد).
 */
class RouteCustomDomainRequests
{
    public const ATTRIBUTE = 'melorin.custom_domain';

    private const PASSTHROUGH = ['up', 'health', 'payment', 'build', 'storage', 'favicon.ico', 'robots.txt'];

    public function __construct(protected ResellerDomainService $domains) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('melorin.domains.enabled', true)) {
            return $next($request);
        }

        $host = strtolower($request->getHost());

        if ($host === '' || $host === $this->domains->platformHost() || $this->isPassthrough($request)) {
            return $next($request);
        }

        try {
            $reseller = $this->domains->resellerForHost($host);
        } catch (Throwable) {
            return $next($request);
        }

        if (! $reseller) {
            return $next($request);
        }

        if ($reseller->status !== 'active') {
            abort(404);
        }

        $rewritten = $this->rewrite($request, $reseller);

        app()->instance('request', $rewritten);

        $this->useDomainUrls($rewritten, $reseller);

        return $next($rewritten);
    }

    /** پس از ارسال پاسخ: وضعیت URL Generator برگردد (برای Worker/تست‌هایی که یک Process چند Request می‌بینند). */
    public function terminate(Request $request, Response $response): void
    {
        // Kernel در terminate «Request اصلی» را می‌دهد؛ علامت روی نسخه‌ی بازنویسی‌شده است که در Container مانده.
        if ($request->attributes->has(self::ATTRIBUTE) || app('request')->attributes->has(self::ATTRIBUTE)) {
            URL::forceRootUrl('');
            URL::formatPathUsing(fn (string $path) => $path);
        }
    }

    protected function isPassthrough(Request $request): bool
    {
        $first = explode('/', trim($request->getPathInfo(), '/'))[0] ?? '';

        return in_array($first, self::PASSTHROUGH, true);
    }

    protected function rewrite(Request $request, Reseller $reseller): Request
    {
        $path = '/'.trim($request->getPathInfo(), '/');
        $target = '/store/'.$reseller->slug.($path === '/' ? '' : $path);
        $query = (string) $request->server->get('QUERY_STRING', '');

        $server = $request->server->all();
        $server['REQUEST_URI'] = $target.($query !== '' ? '?'.$query : '');
        unset($server['PATH_INFO']);

        $rewritten = $request->duplicate(null, null, null, null, null, $server);
        $rewritten->attributes->set(self::ATTRIBUTE, strtolower($request->getHost()));

        return $rewritten;
    }

    protected function useDomainUrls(Request $request, Reseller $reseller): void
    {
        // Cookie نشست فقط برای همین Host؛ SESSION_DOMAIN پلتفرم روی دامنه‌ی دیگر معتبر نیست.
        config(['session.domain' => null]);

        URL::forceRootUrl($request->getScheme().'://'.$request->getHttpHost());

        $prefix = '/store/'.$reseller->slug;

        URL::formatPathUsing(function (string $path) use ($prefix) {
            if ($path === $prefix) {
                return '/';
            }

            return str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix)) : $path;
        });
    }
}
