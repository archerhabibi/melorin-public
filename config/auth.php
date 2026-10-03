<?php

use App\Models\Admin;
use App\Models\User;

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
            // B2.5: مدت اعتبار Cookie «مرا به‌خاطر بسپار» (دقیقه). پیش‌فرض لاراول ۴۰۰ روز است که برای یک
            // فروشگاه مالی زیاد است؛ پیش‌فرض اینجا ۳۰ روز. (SESSION-SECURITY-CONTRACT §S6)
            'remember' => (int) env('AUTH_REMEMBER_MINUTES', 43200),
        ],

        // بند ۲۳ سند: پنل مدیریت Filament از این guard مجزا استفاده
        // می‌کند تا کاربران عادی (User) هرگز به پنل ادمین دسترسی نداشته
        // باشند — فقط رکوردهای جدول admins.
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        // پنل وب نماینده (R5 سند معماری Reseller Platform). همان مدل
        // User (چون طبق «Identity Model»، Reseller یک Profile روی همان
        // User مرکزی است، نه یک هویت جدا) ولی guard جداگانه تا Session
        // پنل نماینده هرگز با ورود مشتری معمولی در سایت (guard web)
        // قاطی نشود.
        'reseller' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],

        'admins' => [
            'provider' => 'admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
