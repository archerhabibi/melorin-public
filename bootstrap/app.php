<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        $middleware->alias([
            'website.csp' => \App\Http\Middleware\SetContentSecurityPolicyHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
