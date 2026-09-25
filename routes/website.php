<?php

use App\Channels\Website\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Channels\Website\Http\Controllers\Auth\NewPasswordController;
use App\Channels\Website\Http\Controllers\Auth\PasswordResetLinkController;
use App\Channels\Website\Http\Controllers\Auth\RegisteredUserController;
use App\Channels\Website\Http\Controllers\Shared\HomeController;
use App\Channels\Website\Http\Controllers\Shared\ProductController;
use Illuminate\Support\Facades\Route;

/*
 * توسط WebsiteServiceProvider::boot() لود می‌شود.
 *
 * تصمیم ۹.۱ (TBD Decision Register): «یک Route Group مشترک با
 * Middleware ResolveStoreContext؛ Controllerها بین دو Context تکرار
 * نمی‌شوند» — دقیقاً به همین دلیل، Main و Reseller روی همین یک تابع
 * تعریف مسیر (registerSharedRoutes) و همین یک مجموعه Controller سوار
 * می‌شوند (پوشه‌ی Shared/)؛ Controllerهای Main/ و Reseller/ فقط برای
 * چیزی که واقعاً منطق UI متفاوت دارد نگه داشته شده‌اند.
 *
 * Reseller روی prefix جدای `/store/{slug}` است، نه `/{slug}` — چون
 * `/{slug}` از قبل توسط ResellerPanelProvider (پنل مدیریتی Filament
 * نماینده، path('') + tenant slugAttribute) اشغال شده است (بند ۹.۱).
 *
 * نکته‌ی مهم پیاده‌سازی: نام route را داخل تابع hardcode نمی‌کنیم
 * (مثلاً 'website.home')، چون همین تابع دوبار فراخوانی می‌شود (یک‌بار
 * برای Main، یک‌بار برای Reseller) و دو Route با نام یکسان، دومی را
 * بی‌صدا جایگزین اولی می‌کند. به‌جایش نام‌های نسبی ('home',
 * 'products.show', ...) و Route::name() روی خودِ Group پیشوند می‌گیرد
 * — نتیجه: website.home در Main، website.store.home در Reseller.
 */
$registerSharedRoutes = function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

    // --- Auth (فاز W1) ---
    Route::middleware('guest')->group(function () {
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');

        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->middleware('throttle:5,1')
            ->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('login.store');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
            ->middleware('throttle:3,60')
            ->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:3,60')
            ->name('password.email');

        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->name('password.store');
    });

    Route::middleware(['auth', 'store.customer'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        // فاز W2 به بعد: /account, /checkout, /orders, ... اینجا اضافه می‌شوند.
    });
};

Route::middleware(['web', 'store.context'])
    ->name('website.')
    ->group($registerSharedRoutes);

Route::middleware(['web', 'store.context'])
    ->prefix('store/{slug}')
    ->name('website.store.')
    ->group($registerSharedRoutes);
