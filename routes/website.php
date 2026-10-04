<?php

use App\Channels\Website\Http\Controllers\Account\AccountsController;
use App\Channels\Website\Http\Controllers\Account\DashboardController;
use App\Channels\Website\Http\Controllers\Account\OrdersController;
use App\Channels\Website\Http\Controllers\Account\ReferralController;
use App\Channels\Website\Http\Controllers\Account\TicketsController;
use App\Channels\Website\Http\Controllers\Account\WalletController;
use App\Channels\Website\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Channels\Website\Http\Controllers\Auth\EmailVerificationController;
use App\Channels\Website\Http\Controllers\Auth\GoogleAuthController;
use App\Channels\Website\Http\Controllers\Auth\NewPasswordController;
use App\Channels\Website\Http\Controllers\Auth\PasswordResetLinkController;
use App\Channels\Website\Http\Controllers\Auth\RegisteredUserController;
use App\Channels\Website\Http\Controllers\Guest\GuestCheckoutController;
use App\Channels\Website\Http\Controllers\Identity\AccountLinkingController;
use App\Channels\Website\Http\Controllers\Identity\ProfileController;
use App\Channels\Website\Http\Controllers\Identity\SessionController;
use App\Channels\Website\Http\Controllers\Identity\TelegramLinkController;
use App\Channels\Website\Http\Controllers\Reseller\ManageController;
use App\Channels\Website\Http\Controllers\Shared\ChargeController;
use App\Channels\Website\Http\Controllers\Shared\CheckoutController;
use App\Channels\Website\Http\Controllers\Shared\HomeController;
use App\Channels\Website\Http\Controllers\Shared\OrderController;
use App\Channels\Website\Http\Controllers\Shared\ProductController;
use App\Channels\Website\Http\Controllers\Shared\ReceiptController;
use Illuminate\Session\Middleware\AuthenticateSession;
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
    // B2.3: لغو نشست Pending و شروع دوباره (باطل‌کردن + پاک‌کردن Cookie).
    Route::post('/guest-checkout/cancel', [GuestCheckoutController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('guest-checkout.cancel');

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

        // B2.1 — Google Sign-In (GOOGLE-SIGNIN-CONTRACT.md). شروع در هر دو Context؛ callback ثابت و فقط Main
        // (پایین‌تر). بدون GOOGLE_CLIENT_ID/SECRET هر دو 404 می‌دهند.
        Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])
            ->middleware('throttle:10,1')
            ->name('auth.google.redirect');

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

    // B2.5: AuthenticateSession نشست‌های دیگر را با تغییر Hash رمز می‌بندد (مستقل از Session Driver).
    Route::middleware(['auth', AuthenticateSession::class, 'store.customer'])->group(function () {
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
        // B3.5: ویرایش نام و موبایل (CUSTOMER-PROFILE-CONTRACT.md). نوشتن است ⇒ throttle (به‌ازای کاربر).
        Route::post('/profile', [ProfileController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('identity.profile.update');

        // --- B2.4 Account Linking (ACCOUNT-LINKING-CONTRACT.md) ---
        // اتصال Google: شروع از Profile (callback همان Redirect URI ثابت B2.1). بدون Google فعال ⇒ 404.
        Route::get('/identity/google/link', [GoogleAuthController::class, 'linkRedirect'])
            ->middleware('throttle:10,1')
            ->name('identity.google.link');
        // جداسازی و تعیین اولین رمز: POST + رمز فعلی (L5) + throttle سخت.
        Route::post('/identity/google/unlink', [AccountLinkingController::class, 'unlinkGoogle'])
            ->middleware('throttle:5,1')
            ->name('identity.google.unlink');
        Route::post('/identity/telegram/unlink', [AccountLinkingController::class, 'unlinkTelegram'])
            ->middleware('throttle:5,1')
            ->name('identity.telegram.unlink');
        Route::post('/identity/password', [AccountLinkingController::class, 'setPassword'])
            ->middleware('throttle:5,1')
            ->name('identity.password.set');

        // --- B2.5 Session Security (SESSION-SECURITY-CONTRACT.md) ---
        // تغییر رمز موجود (رمز فعلی لازم) و پایان نشست‌های فعال؛ همه POST + throttle سخت (به‌ازای کاربر).
        Route::post('/identity/password/update', [AccountLinkingController::class, 'updatePassword'])
            ->middleware('throttle:5,1')
            ->name('identity.password.update');
        Route::post('/identity/sessions/revoke', [SessionController::class, 'revoke'])
            ->middleware('throttle:5,1')
            ->name('identity.sessions.revoke');
        Route::post('/identity/sessions/revoke-others', [SessionController::class, 'revokeOthers'])
            ->middleware('throttle:5,1')
            ->name('identity.sessions.revoke-others');

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

        // --- B3.1 Customer Dashboard: نقطه‌ی ورود پنل کاربری ---
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');

        // --- پنل کاربری: Wallet + Orders + Accounts ---
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
        Route::get('/accounts', [AccountsController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}', [AccountsController::class, 'show'])->name('accounts.show');

        // --- پنل کاربری: Renewal + مصرف (B3.2) ---
        // renew: پول کسر می‌کند ⇒ throttle سخت (علاوه بر توکن Idempotency S-07). usage.refresh: هر بار
        // یک تماس به پنل خارجی است؛ علاوه بر این Throttle، Core هم برای هر سرویس ۱۲۰ ثانیه فاصله می‌گذارد.
        Route::post('/accounts/{account}/renew', [AccountsController::class, 'renew'])
            ->middleware('throttle:6,1')
            ->name('accounts.renew');
        Route::post('/accounts/{account}/usage/refresh', [AccountsController::class, 'refreshUsage'])
            ->middleware('throttle:12,1')
            ->name('accounts.usage.refresh');

        // --- پنل کاربری: Ticket Center (B3.4 — CUSTOMER-TICKETS-CONTRACT.md) ---
        // ثبت/پاسخ/بستن نوشتن‌اند ⇒ throttle (ضد اسپم)؛ مالکیت و Context داخل Core بررسی می‌شود (نه Route Model Binding).
        Route::get('/tickets', [TicketsController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/new', [TicketsController::class, 'create'])->name('tickets.create');
        Route::post('/tickets', [TicketsController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('tickets.store');
        Route::get('/tickets/{ticket}', [TicketsController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::post('/tickets/{ticket}/reply', [TicketsController::class, 'reply'])
            ->whereNumber('ticket')
            ->middleware('throttle:10,1')
            ->name('tickets.reply');
        Route::post('/tickets/{ticket}/close', [TicketsController::class, 'close'])
            ->whereNumber('ticket')
            ->middleware('throttle:10,1')
            ->name('tickets.close');

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
    Route::prefix('manage')->name('manage.')->middleware(['auth', AuthenticateSession::class])->group(function () {
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
Route::middleware(['web', 'store.context', 'website.csp', 'website.session', 'auth', AuthenticateSession::class])->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/*
 * Google Sign-In callback (B2.1). Redirect URI گوگل باید یک آدرس ثابت باشد، پس فقط روی Context اصلی
 * ثبت می‌شود؛ مقصد برگشت (نماینده/ادامه‌ی خرید) سمت سرور در Session نگه داشته شده است.
 * کاربر واردشده فقط با نشست اتصال (B2.4) به این مسیر وارد می‌شود؛ کنترلر آن را enforce می‌کند.
 */
Route::middleware(['web', 'store.context', 'website.csp', 'website.session'])->group(function () {
    // `guest` برداشته شد (B2.4): کاربر واردشده برای «اتصال» (mode=link در Session) باید به همین
    // callback ثابت برگردد. کاربر واردشده بدون نشست اتصال داخل کنترلر به Home هدایت می‌شود.
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('auth.google.callback');
});

// CSP فقط روی هر دو گروه Website اعمال می‌شود — نه سراسری روی 'web'
// (پنل‌های ادمین/نماینده دست‌نخورده می‌مانند).
Route::middleware(['web', 'store.context', 'website.csp', 'website.session'])
    ->name('website.')
    ->group($registerSharedRoutes);

Route::middleware(['web', 'store.context', 'website.csp', 'website.session'])
    ->prefix('store/{slug}')
    ->name('website.store.')
    ->group(function () use ($registerSharedRoutes, $registerResellerManagementRoutes) {
        $registerSharedRoutes();
        $registerResellerManagementRoutes();
    });
