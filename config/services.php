<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * درگاه پرداخت آنلاین زرین‌پال (P2 گزارش امنیتی، مورد #17).
     * ZarinpalGateway از قبل کامل بود ولی این کلید و route بازگشتش
     * هرگز تعریف نشده بودند — یعنی هر تلاش برای پرداخت آنلاین با
     * RuntimeException شکست می‌خورد. callback_url پیش‌فرض روی همان
     * routeای تنظیم شده که در routes/web.php ثبت شده تا در نصب
     * معمولی نیازی به هیچ تنظیم دستی نباشد.
     */
    'zarinpal' => [
        'callback_url' => env('ZARINPAL_CALLBACK_URL', env('APP_URL').'/payment/zarinpal/callback'),
        'sandbox' => env('ZARINPAL_SANDBOX', false),
    ],

];
