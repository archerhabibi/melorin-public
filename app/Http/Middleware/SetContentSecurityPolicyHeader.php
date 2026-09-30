<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * فاز W6 بند ۲ (Roadmap) + بخش ۹.۶ («CSP | Header پایه: self + دامنه‌ی
 * درگاه‌های پرداخت مجاز + (در صورت استفاده) دامنه‌ی ویجت Telegram
 * Login»).
 *
 * فقط روی گروه Route های Website اعمال می‌شود (routes/website.php، هر
 * دو گروه Main و Reseller) — نه سراسری روی web — چون پنل ادمین
 * (Filament/Livewire) به Inline Script/Style های خودش وابسته است و
 * یک CSP سخت‌گیرانه‌ی سراسری آن را می‌شکند.
 *
 * style-src 'unsafe-inline': صفحات Website از ویژگی inline
 * style="..." برای رنگ Brand نماینده استفاده می‌کنند (چون این رنگ در
 * Build-time مشخص نیست، در Runtime از StoreContext می‌آید). حذف این
 * ویژگی یعنی بازنویسی همه‌ی Viewهای موجود؛ خارج از Scope همین پچ،
 * به‌عنوان کار آینده در مستند پچ ثبت شده. Inline Style بسیار
 * کم‌خطرتر از Inline Script است، پس این یک سازش آگاهانه است.
 *
 * script-src: فقط self + telegram.org (ویجت رسمی Telegram Login، پچ
 * ۳.۲.۵) — بدون unsafe-inline روی اسکریپت.
 */
class SetContentSecurityPolicyHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://telegram.org",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
        ]));

        return $response;
    }
}
