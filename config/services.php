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

    /*
     * Google Sign-In (B2.1؛ GOOGLE-SIGNIN-CONTRACT.md). OIDC Authorization Code + PKCE،
     * بدون Socialite. تا وقتی هر دو کلید تنظیم نشده‌اند، قابلیت کاملاً خاموش است
     * (دکمه نمایش داده نمی‌شود و Routeها 404 می‌دهند).
     *
     * redirect: باید دقیقاً با «Authorized redirect URI» در Google Cloud Console یکی باشد.
     * فقط یک آدرس ثابت روی Context اصلی (نمایندگان هم از همین callback استفاده می‌کنند).
     * auto_link: اگر true باشد و User محلی با همان Email «تأییدشده» وجود داشته باشد،
     * هویت Google به آن User وصل می‌شود (G14)؛ اگر false باشد، کاربر باید ابتدا با رمز وارد شود.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'enabled' => filled(env('GOOGLE_CLIENT_ID')) && filled(env('GOOGLE_CLIENT_SECRET')),
        'auto_link' => env('GOOGLE_AUTO_LINK_VERIFIED_EMAIL', true),
    ],

];
