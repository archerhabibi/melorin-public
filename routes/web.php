<?php

use Illuminate\Support\Facades\Route;

/*
 * عمداً Route::get('/') اینجا تعریف نمی‌شود: مسیر «/» متعلق به
 * website.home است (routes/website.php). Laravel کلیدِ allRoutes را از
 * «متد + URI» می‌سازد؛ یک Route دیگر روی GET / باعث می‌شد website.home
 * بی‌صدا از فهرست نام‌ها حذف شود (RouteNotFoundException در Layout).
 */

/*
 * بازگشت از درگاه پرداخت آنلاین (P2 گزارش امنیتی، مورد #17).
 * بدون امضا/احراز هویت است — چون کاربر از سایت زرین‌پال به اینجا
 * ریدایرکت می‌شود و session ای همراهش نیست؛ امنیت واقعی در خودِ
 * PaymentCallbackController با verify سمت‌به‌سمت تأمین می‌شود، نه با
 * اعتماد به پارامترهای URL.
 */
Route::get('/payment/zarinpal/callback', [\App\Http\Controllers\PaymentCallbackController::class, 'zarinpal'])
    ->name('payment.zarinpal.callback');
