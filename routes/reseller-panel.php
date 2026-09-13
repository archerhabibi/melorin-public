<?php

use App\Http\Controllers\ResellerLoginController;
use Illuminate\Support\Facades\Route;

/**
 * برخلاف routes/reseller-bot.php (که یک وب‌هوک stateless است و عمداً
 * بیرون از گروه میان‌افزار 'web' بارگذاری می‌شود)، این مسیر باید حتماً
 * داخل گروه 'web' باشد — چون هم به Session نیاز دارد (تا
 * Auth::guard('reseller')->login() واقعاً پایدار بماند) و هم به
 * SubstituteBindings (تا {reseller} در URL به یک نمونه‌ی واقعیِ
 * Eloquent Model تبدیل شود، نه یک شیء خالیِ ساخته‌شده توسط کانتینر).
 */
Route::middleware('web')->group(function () {
    Route::get('/reseller-login/{reseller}', ResellerLoginController::class)
        ->middleware('signed')
        ->name('reseller.login');
});
