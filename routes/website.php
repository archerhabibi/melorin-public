<?php

use App\Channels\Website\Http\Controllers\Account\AccountsController;
use App\Channels\Website\Http\Controllers\Account\OrdersController;
use App\Channels\Website\Http\Controllers\Account\ReferralController;
use App\Channels\Website\Http\Controllers\Account\WalletController;
use App\Channels\Website\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Channels\Website\Http\Controllers\Auth\EmailVerificationController;
use App\Channels\Website\Http\Controllers\Auth\NewPasswordController;
use App\Channels\Website\Http\Controllers\Auth\PasswordResetLinkController;
use App\Channels\Website\Http\Controllers\Auth\RegisteredUserController;
use App\Channels\Website\Http\Controllers\Guest\GuestCheckoutController;
use App\Channels\Website\Http\Controllers\Identity\ProfileController;
use App\Channels\Website\Http\Controllers\Identity\TelegramLinkController;
use App\Channels\Website\Http\Controllers\Reseller\ManageController;
use App\Channels\Website\Http\Controllers\Shared\ChargeController;
use App\Channels\Website\Http\Controllers\Shared\CheckoutController;
use App\Channels\Website\Http\Controllers\Shared\HomeController;
use App\Channels\Website\Http\Controllers\Shared\OrderController;
use App\Channels\Website\Http\Controllers\Shared\ProductController;
use App\Channels\Website\Http\Controllers\Shared\ReceiptController;
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

    // --- Guest Checkout (Master 2.7 §3) — عمداً بدون auth ---
    // Guest فقط «شروع» Checkout است: فرم (email*) → Pending → Login/Register →
    // ادامه‌ی همان خرید. مسیر قدیمی POST /guest-checkout/purchase (ساخت User و
    // Auth::login خودکار) DEPRECATED و حذف شد (X4).
    Route::get('/products/{product}/guest-checkout', [GuestCheckoutController::class, 'show'])
        ->name('guest-checkout.show');
    Route::post('/products/{product}/guest-checkout', [GuestCheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('guest-checkout.store');
    Route::get('/guest-checkout/pending', [GuestCheckoutController::class, 'pending'])
        ->name('guest-checkout.pending');

    // --- Auth ---
    Route::middleware('guest')->group(function () {
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        // S-05 (فاز ۸): بدون محدودیت، ثبت‌نام انبوه و ایمیل‌بمب (هر ثبت‌نام یک ایمیل
        // تأیید به آدرس دلخواه می‌فرستد) ممکن بود.
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('throttle:10,60')
            ->name('register.store');

        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->middleware('throttle:5,1')
            ->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('login.store');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
            ->middleware('throttle:3,60')
            ->name('password.request');
        // محدودیت اصلی «۳/ساعت به‌ازای ایمیل» داخل خودِ کنترلر است (خطای
        // اعتبارسنجی روی فیلد email). throttle:3,60 روی IP قبل از آن اجرا
        // می‌شد و ۴مین درخواست را با 429 می‌بُرید؛ این‌جا فقط سقف سخاوتمندانه‌ی
        // ضد-سوءاستفاده برای IP می‌ماند.
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:30,60')
            ->name('password.email');

        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->name('password.store');
    });

    Route::middleware(['auth', 'store.customer'])->group(function () {
        // آدرس '/sign-out' (نه '/logout'): مسیر POST /logout برای خروج
        // پنل نماینده‌ی Filament (path('')) رزرو است و ثابت است (قابل
        // تغییر نیست). نام route همچنان logout می‌ماند، پس فقط URI عوض شد.
        Route::post('/sign-out', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

        // --- Commerce هسته‌ای، بدون Cart ---
        // throttle روی POST checkout طبق تصمیم بخش ۹.۶ (Rate Limiting
        // روی مسیرهای حساس) — یک مسیر مالی است، نباید بدون محدودیت بماند.
        Route::get('/products/{product}/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('/products/{product}/checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('checkout.store');

        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        // --- Profile / اتصال Telegram (Master G10) ---
        Route::get('/profile', [ProfileController::class, 'show'])->name('identity.profile.show');

        // --- Telegram-linking ---
        // throttle: هر تلاش یک درخواست HMAC-verify است؛ محدودیت جلوی
        // Brute-force حدس hash را می‌گیرد (هرچند خودِ HMAC عملاً غیرقابل‌حدس
        // است، این یک لایه‌ی دفاعی اضافه است، نه تکیه‌گاه اصلی امنیت).
        Route::get('/identity/telegram/callback', [TelegramLinkController::class, 'callback'])
            ->middleware('throttle:20,1')
            ->name('identity.telegram.callback');

        // --- شارژ کیف‌پول: Zarinpal + Card-to-Card ---
        Route::get('/wallet/charge', [ChargeController::class, 'show'])->name('wallet.charge.show');
        Route::post('/wallet/charge', [ChargeController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('wallet.charge.store');

        Route::get('/wallet/charge/{payment}/receipt', [ReceiptController::class, 'show'])->name('wallet.receipt.show');
        Route::post('/wallet/charge/{payment}/receipt', [ReceiptController::class, 'store'])
            // بخش ۹.۶: «Upload رسید: ۵/دقیقه».
            ->middleware('throttle:5,1')
            ->name('wallet.receipt.store');

        // --- پنل کاربری: Wallet + Orders + Accounts ---
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
        Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}', [AccountsController::class, 'show'])->name('accounts.show');

        // --- پنل کاربری: Renewal ---
        Route::post('/accounts/{account}/renew', [AccountsController::class, 'renew'])->name('accounts.renew');

        // --- پنل کاربری: Referral/Commission ---
        Route::get('/referral', [ReferralController::class, 'show'])->name('referral.show');

        // Refund و Retry کاملاً Admin-only هستند — مثل ربات تلگرام که هیچ
        // دکمه‌ی Refund/Retry به مشتری نشان نمی‌دهد. Website فقط برچسب
        // وضعیت را در فهرست سفارش‌ها نشان می‌دهد («ساخت ناموفق — نیازمند
        // رسیدگی» / «بازگشت‌شده») و هیچ Route یا اکشنی برای آن‌ها ندارد.
    });
};

/*
 * مدیریتِ فروشگاه نماینده (Branding، مشتریان، قیمت‌ها).
 * عمداً فقط زیرِ `website.store.*` ثبت می‌شود (نه در $registerSharedRoutes،
 * که بین Main و Reseller مشترک است) — «مدیریت فروشگاه نماینده» بدون
 * یک نماینده در Context بی‌معناست. مجوزِ واقعی (آیا این کاربر
 * ادمین/مالکِ همین نماینده است؟) داخل خودِ ManageController است، نه
 * Middleware — دلیلش در docblock همان کنترلر.
 */
$registerResellerManagementRoutes = function () {
    Route::prefix('manage')->name('manage.')->middleware('auth')->group(function () {
        Route::get('/', [ManageController::class, 'index'])->name('index');
        Route::get('/customers', [ManageController::class, 'customers'])->name('customers');
        Route::get('/products', [ManageController::class, 'products'])->name('products');
        Route::post('/products/{product}/price', [ManageController::class, 'setPrice'])->name('products.price');
        Route::post('/products/{product}/enable', [ManageController::class, 'enable'])->name('products.enable');
        Route::post('/products/{product}/disable', [ManageController::class, 'disable'])->name('products.disable');
        Route::get('/branding', [ManageController::class, 'branding'])->name('branding');
        Route::post('/branding', [ManageController::class, 'updateBranding'])->name('branding.update');
    });
};

/*
 * Email Verification (Master 2.7 G11، شکاف C10). فقط Context اصلی (لینک Email
 * از Queue ارسال می‌شود و StoreContext ندارد)؛ نام‌های استاندارد Laravel.
 * Rate Limit: ۶/دقیقه (RATE-LIMIT در Website Contract §22).
 */
Route::middleware(['web', 'store.context', 'website.csp', 'auth'])->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// CSP فقط روی هر دو گروه Website اعمال می‌شود — نه سراسری روی 'web'
// (پنل‌های ادمین/نماینده دست‌نخورده می‌مانند).
Route::middleware(['web', 'store.context', 'website.csp'])
    ->name('website.')
    ->group($registerSharedRoutes);

Route::middleware(['web', 'store.context', 'website.csp'])
    ->prefix('store/{slug}')
    ->name('website.store.')
    ->group(function () use ($registerSharedRoutes, $registerResellerManagementRoutes) {
        $registerSharedRoutes();
        $registerResellerManagementRoutes();
    });
