<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * بازگشت از درگاه پرداخت آنلاین (P2 گزارش امنیتی، مورد #17).
 * بدون امضا/احراز هویت است — چون کاربر از سایت زرین‌پال به اینجا
 * ریدایرکت می‌شود و session ای همراهش نیست؛ امنیت واقعی در خودِ
 * PaymentCallbackController با verify سمت‌به‌سمت تأمین می‌شود، نه با
 * اعتماد به پارامترهای URL.
 */
Route::get('/payment/zarinpal/callback', [\App\Http\Controllers\PaymentCallbackController::class, 'zarinpal'])
    ->name('payment.zarinpal.callback');
