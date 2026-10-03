<?php

use App\Http\Controllers\Ops\HealthController;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetContentSecurityPolicyHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // فاز ۹ — Readiness (بدون Session/CSRF تا پایشگر هر ۳۰ ثانیه Session نسازد).
            Route::get('/health/ready', HealthController::class)
                ->middleware('throttle:60,1')
                ->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // بدون این callback، میان‌افزار 'auth' (guard پیش‌فرض 'web') روی
        // مسیرهای Website سعی می‌کند به route('login') برود که اصلاً
        // وجود ندارد (نام واقعی route ما website.login /
        // website.store.login است — بند ۹.۱) و با RouteNotFoundException
        // می‌شکند. فقط guard 'web' را پوشش می‌دهد؛ guardهای admin/reseller
        // مسیر ورود خودشان را از طریق Filament مدیریت می‌کنند، نه اینجا.
        $middleware->redirectGuestsTo(function ($request) {
            $slug = $request->route('slug');

            return $slug
                ? route('website.store.login', $slug)
                : route('website.login');
        });

        // فاز W6 بند ۲ (Roadmap، مالکیت نفر ۴): CSP فقط با نام مستعار
        // ثبت می‌شود، نه به‌صورت سراسری روی گروه 'web' اضافه می‌شود —
        // فقط routes/website.php آن را به گروه‌های خودش اضافه می‌کند تا
        // پنل ادمین/نماینده (Filament) که به این گروه global متکی است
        // دست‌نخورده بماند.
        // S-04 (فاز ۸): پشت Nginx/Cloudflare Tunnel بدون TrustProxies، همه‌ی
        // کاربران با IP لوپ‌بک (127.0.0.1) دیده می‌شدند ← Rate Limitهای مبتنی بر IP
        // (لاگین ۵/دقیقه، …) بین «همه‌ی کاربران» مشترک می‌شد (DoS با ۵ درخواست) و
        // IP واقعی در Audit/Log ثبت نمی‌شد. پیش‌فرض فقط لوپ‌بک است؛ برای پراکسی
        // روی میزبان دیگر `TRUSTED_PROXIES` را (با ویرگول) تنظیم کنید.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // S-08: هدرهای امنیتی پایه روی همه‌ی پاسخ‌های وب (Website + پنل‌های Filament).
        $middleware->appendToGroup('web', SecurityHeaders::class);

        $middleware->alias([
            'website.csp' => SetContentSecurityPolicyHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
