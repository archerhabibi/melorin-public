# فاز W0+W1 — Website Channel: زیرساخت و Auth (v3.2.0)

مرجع: `ROADMAP-WEBSITE-v1.md` (فازهای W0، W1) + `Melorin_Website_Architecture_Subdocument_v1_1.md`
+ TBD Decision Register (بخش ۹ همان سند).

این فاز اولین کدِ واقعیِ Channel جدید «Website» است — نه صرفاً طرح.
پچ متناظر: `melorin-website-v3.2.0.patch`.

## چه چیزی ساخته شد

### فاز W0 — زیرساخت Channel (کامل)
- `app/Channels/Website/WebsiteServiceProvider.php` — هم‌الگو با `TelegramBotServiceProvider`.
- `ResolveStoreContext` — از روی `{slug}` مسیر، `StoreContext::main()`/`::reseller()` را در Container bind می‌کند؛ نماینده‌ی نامعتبر/غیرفعال → 404 (نه 403، طبق تصمیم بند ۳).
- `WebsiteCatalogFacade`, `WebsiteWalletFacade` — Adapter نازک روی `ResellerPricingService`, `WalletService`؛ صفر منطق تصمیم‌گیری.
- `CoreErrorMapper` — آرایه‌ی ثابت Exception→پیام فارسی + Reference ID (تصمیم ۹.۵).
- `routes/website.php` — تصمیم ۹.۱: Main روی `/`، Reseller روی `/store/{slug}` (نه `/{slug}`، چون آن مسیر از قبل توسط `ResellerPanelProvider::path('')` با tenant `slugAttribute` اشغال شده).

### فاز W1 — Auth (کامل، دست‌نویس)
- `RegisteredUserController`, `AuthenticatedSessionController`, `PasswordResetLinkController`, `NewPasswordController` — معادل Breeze، دست‌نویس (بدون نصب پکیج، چون هنگام نوشتن این پچ محیط build به Packagist دسترسی نداشت).
- `RegisterRequest`, `LoginRequest` — اعتبارسنجی سمت‌سرور + Rate limit طبق تصمیم ۹.۶ (Login: ۵/دقیقه به‌ازای ایمیل+IP).
- `EnsureCustomerAccountResolved` — بعد از `auth`، `CustomerAccount` همان User در همان `StoreContext` را از طریق `IdentityService::resolveCustomerAccount` می‌سازد/می‌خواند (بند ۵ فاز W1: «نه User مستقل»).
- Migration جدید: دو ستون گم‌شده در `users` — `email_verified_at` (تصمیم ۹.۲: Soft verification) و `remember_token` (تصمیم ۹.۲/۹.۶: «مرا به‌خاطر بسپار»).

### فاز W2 — فقط بخش مرور محصولات (ناقص، عمدی)
- `HomeController`, `ProductController` — فقط نمایش؛ قیمت مستقیماً از `Product::mainPrice()`/`customersPrice()` (بند ۱ Roadmap: بدون محاسبه‌ی قیمت در Website).
- **Checkout/Payment در این پچ نیست.** `WebsitePurchaseFacade` که باید `PurchaseService::purchase()` را با `idempotencyKey` صدا بزند، هنوز نوشته نشده — این تصمیم عمدی بود چون این تکه مستقیماً کیف‌پول و Provisioning واقعی را لمس می‌کند و باید جایی با PHP/DB واقعی تست شود، نه در sandboxی که این پچ در آن نوشته شد.

## تغییر در فایل‌های موجود پروژه

| فایل | تغییر | چرا |
|---|---|---|
| `bootstrap/providers.php` | افزودن `WebsiteServiceProvider::class` | ثبت Channel جدید |
| `bootstrap/app.php` | `redirectGuestsTo` برای guard پیش‌فرض (`web`) | بدون آن، میان‌افزار `auth` روی مسیرهای Website سعی می‌کند به route با نام دقیق `login` برود که وجود ندارد (نام واقعی `website.login`/`website.store.login` است) و با `RouteNotFoundException` می‌شکند. فقط guard `web`؛ `admin`/`reseller` دست‌نخورده ماندند (آن‌ها را Filament مدیریت می‌کند) |
| `package.json`, `resources/js/app.js` | افزودن `alpinejs` + بوت آن | تصمیم ۹.۷ (Blade + Alpine.js + Tailwind) |

## باگی که حین کار پیدا و رفع شد

نسخه‌ی اول `routes/website.php` یک نام یکسان (`website.home` و …) برای
هر دو گروه Main و Reseller ثبت می‌کرد؛ چون این فایل با یک تابع مشترک
دوبار (`Route::group`) فراخوانی می‌شود، ثبت دوم بی‌صدا ثبت اول را
override می‌کرد و `route('website.home', ['slug' => ...])` هیچ‌وقت به
مسیر نماینده نمی‌رفت. با انتقال نام‌گذاری به `Route::name('website.')`
در برابر `Route::name('website.store.')` روی خودِ Group، و نام‌های
نسبی (`home`, `products.show`, …) داخل تابع مشترک، رفع شد.

## محدودیت این پچ

این کد در محیطی بدون PHP/Composer/دسترسی به Packagist نوشته شده — پس
`composer install`، `artisan migrate`، و تست واقعی روی آن اجرا نشده.
قبل از merge حتماً روی Staging اجرا و `php artisan route:list` چک شود
(به‌خصوص برای اطمینان از نبود تداخل `/store/{slug}` با مسیرهای پنل
نماینده‌ی Filament).

## قدم بعدی

`W2` باقی‌مانده: `WebsitePurchaseFacade`، Checkout بدون Cart (بند
۱۸/۱۹)، انتخاب روش پرداخت (بند ۹.۵)، نمایش وضعیت سفارش/Provisioning
(بند ۲۲/۲۳/۳۹/۴۰)، Idempotency واقعی در فرم (بند ۵۳/۵۴). سپس W3 (Guest
Checkout) طبق ترتیب خودِ Roadmap.
