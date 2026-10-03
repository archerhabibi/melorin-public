<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * هدرهای امنیتی پایه (فاز ۸، S-08). CSP عمداً اینجا نیست: CSP فقط روی Website
 * اعمال می‌شود (SetContentSecurityPolicyHeader) تا Filament دست‌نخورده بماند.
 *
 * - X-Content-Type-Options: جلوگیری از MIME-sniffing.
 * - Referrer-Policy: Token/ID داخل URL (مثلاً لینک‌های امضاشده) به سایت‌های بیرونی نشت نکند.
 * - X-Frame-Options: Clickjacking (هم‌راستا با frame-ancestors 'self' در CSP).
 * - Permissions-Policy: قابلیت‌های حساس مرورگر که این برنامه نیاز ندارد.
 * - HSTS: فقط روی HTTPS واقعی (یا پشت پراکسی مورد اعتماد)، تا HTTP محلی/تست خراب نشود.
 *
 * هدری که قبلاً توسط کنترلر/میان‌افزار دیگری ست شده باشد بازنویسی نمی‌شود.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
