# فاز W3 (بخش دوم) — تکمیل خرید مهمان + مالکیت پوشه‌ها (v3.2.4)

مرجع: `ROADMAP-WEBSITE-v1.md` بخش ۱۰ («تقسیم کار بین ۵ نفر») + فاز W3
بندهای ۳، ۴، ۵ + بند ۸۷/۲۴ سند مادر + `docs/PHASE-W3-PART1-GUEST-CHECKOUT-TOKEN.md`.

**من در این پچ نقش «نفر ۱» (هویت مهمان + اتصال تلگرام) را ایفا
می‌کنم** — طبق نقشه‌ی مالکیت بخش ۱۰، فقط این پوشه‌ها را دست می‌زنم:
`Controllers/Guest/`, `Controllers/Identity/`, `views/website/guest/`,
`views/website/identity/`، به‌علاوه‌ی نقاط اشتراکی مجاز
(`routes/website.php` فقط با افزودن، نه ویرایش گروه دیگران؛ Website
Facade Services فقط با افزودن متد جدید).

## بخش اول این پچ: انتقال به پوشه‌های مالکیتی (Refactor بدون تغییر رفتار)

کار پچ ۳.۲.۳ (Guest Checkout Token) قبل از انتشار بخش ۱۰ Roadmap
انجام شده بود و در `Controllers/Shared/` و `views/website/shared/`
بود. این پچ آن را **بدون تغییر رفتار** جابه‌جا کرد:

- `Controllers/Shared/GuestCheckoutController.php` → `Controllers/Guest/GuestCheckoutController.php`
- `views/website/shared/guest-checkout-{start,pending}.blade.php` → `views/website/guest/checkout-{start,pending}.blade.php`

این جابه‌جایی طبق بند ۱۰ لازم بود تا Merge نهایی با کار نفرات دیگر
تداخل نداشته باشد.

## بخش دوم: رابط قابل‌استفاده‌ی مجدد برای نفر ۳ (وابستگی، بند ۱۷)

`views/website/guest/entry-link.blade.php` — یک Partial مستقل که فقط
به `$product` و `$store` نیاز دارد و هیچ Layout/CSS خاصی فرض نمی‌کند؛
نفر ۳ می‌تواند همین حالا با `@include('website.guest.entry-link', [...])`
دکمه‌ی «خرید به‌عنوان مهمان» را در فروشگاه نماینده نشان دهد، بدون
انتظار برای بقیه‌ی کار من. `product-show.blade.php` (که خودم در W2
ساخته بودم) هم به همین Partial تبدیل شد تا یک نسخه از این UI بیشتر
وجود نداشته باشد.

## بخش سوم: بند ۳ — تکمیل خرید واقعی

### تصمیم معماری (چرا، نه فقط چی)

`PurchaseService::purchase()` یک `CustomerAccount` الزامی می‌گیرد و
`PaymentService::initiate()` یک `User` الزامی می‌گیرد. دو گزینه بود:

1. **تغییر امضای این دو متد Core** تا مسیر مهمان را هم بپذیرند.
2. **استفاده از یک User «ناقص»**: دقیقاً همان مفهومی که بند Identity
   Completeness سند مادر از قبل تعریف کرده («CustomerAccount ساخته‌شده
   از ایمیل به‌تنهایی ناقص تلقی می‌شود، اما این ناقص‌بودن مانع خرید/
   Payment/Provisioning نیست»). این پچ **گزینه‌ی ۲** را انتخاب کرد.

چرا ۲: هیچ خط Core تغییر نمی‌کند؛ `PurchaseService`/`PaymentService`
همان‌طور که هستند (تست‌شده در پچ‌های قبلی) استفاده می‌شوند. دقیقاً
همان الگویی که خودِ `RegisteredUserController` (فاز W1) از قبل به
کار برده: «ساختن یک User، منطق کسب‌وکار Core محسوب نمی‌شود» —
CustomerAccount هر Context به‌صورت Lazy توسط میان‌افزار موجود
`EnsureCustomerAccountResolved` ساخته می‌شود، نه اینجا.

### چه اتفاقی می‌افتد (`GuestPurchaseController`)

1. توکن مهمان از Cookie خوانده می‌شود (همان بند ۱).
2. **حیاتی (بند ۸۷: «merge خودکار بدون اثبات ممنوع»)**: با
   `IdentityService::findIdentity()` چک می‌شود آیا تلفن/ایمیل مهمان از
   قبل به یک User واقعی تعلق دارد. اگر بله → **هرگز** به آن وصل
   نمی‌شویم؛ کاربر به Login هدایت می‌شود (با `url.intended` تنظیم‌شده
   تا بعد از ورود مستقیم به همان Checkout برسد).
3. اگر نه → یک `User` جدید با `phone`/`email`/`full_name` مهمان و
   `password = null` ساخته می‌شود (`joined_from = 'website_guest_checkout'`
   برای گزارش‌گیری بعدی)، بلافاصله `Auth::login()`.
4. `GuestCheckout` به `consumed` تغییر می‌کند (جلوگیری از استفاده‌ی
   دوباره‌ی همان توکن).
5. Redirect مستقیم به همان `CheckoutController::show` تست‌شده‌ی پچ
   ۳.۲.۱ — از این‌جا به بعد، مهمان دقیقاً همان مسیر کاربر لاگین‌کرده را
   طی می‌کند: اگر موجودی کافی نبود، همان `ChargeController` (پچ ۳.۲.۲،
   Zarinpal/Card-to-Card) در دسترس است.
6. Race condition (دو تب هم‌زمان): `User::create` روی `phone`/`email`
   یکتا محافظت می‌شود؛ اگر تصادم خورد (`QueryException`)، دقیقاً مثل
   حالت ۲ به Login هدایت می‌شود — همان الگوی race-guard که
   `IdentityService::resolveCustomerAccount` قبلاً استفاده می‌کند.

## بخش چهارم: بند ۴ — Identity Resolution

با طراحی بالا، «Identity Resolution» عملاً **قبل از خرید** و به شکل
امن (فقط رد یا قبول، هرگز merge خودکار) اتفاق می‌افتد، نه بعدش با یک
مکانیزم جدا. این ساده‌تر از چیزی است که Roadmap فرض کرده بود (بند ۹:
«شکست Identity Resolution نباید خرید موفق را باطل کند») چون اصلاً
خرید موفق، پیش از احراز هویت (ولو ناقص) اتفاق نمی‌افتد — پس شکست
Resolution همان «هدایت به Login» است، نه یک مرحله‌ی جدا بعد از
Provisioning.

## بخش پنجم: بند ۵ — Guest Claiming Flow (نسخه‌ی محدود)

`Controllers/Identity/CompleteProfileController` — یک صفحه‌ی ساده:
User ناقص (بدون رمز عبور) می‌تواند رمز عبور بگذارد تا دفعه‌ی بعد بدون
خرید جدید وارد شود. لینک آن در `order-show.blade.php` (پچ ۳.۲.۱، فقط
شرط `@if(!auth()->user()->password)` اضافه شد) بعد از خرید موفق نشان
داده می‌شود.

**این «Claiming» به‌معنای Roadmap کامل نیست** — `attachGuestToIdentity`
در `IdentityService` (برای مهمان‌های بدون User، یعنی
`createGuestAccount`) اینجا اصلاً استفاده نشد، چون این پچ از ابتدا یک
User واقعی (نه CustomerAccount بی‌صاحب) می‌سازد. اتصال Telegram (نیمه‌ی
دوم بند ۵ + بخشی که از W6 منتقل شد) در این پچ نیست.

## خارج از Scope این پچ (عمدی)

- **Telegram-linking واقعی** (Login Widget با HMAC معتبر یا
  Deep-Link/کد یک‌بارمصرف) — طبق تصمیم Roadmap این هم در فاز W3
  می‌ماند، اما به‌عمد پچ جدا می‌خواهد: مکانیزم امنیتی مستقل است، نمونه‌ی
  مرجع (VPNMarket) دقیقاً همین‌جا HMAC جعلی داشته، و Roadmap خودش
  توصیه کرده «هر تغییر امنیتی مستقل Review شود». پچ بعدی (۳.۲.۵).
- بند ۶ (تست Idempotency) — تا حدی همین پچ پوشش داد
  (`reusing_a_consumed_guest_token_is_rejected`، race-guard روی
  `User::create`)؛ پوشش کامل‌تر (چند Job/Request واقعاً هم‌زمان) به
  نفر ۵ (Verification Matrix) موکول است.

## تست

`tests/Feature/Website/GuestPurchaseFlowTest.php`: مهمان جدید تا
Checkout، مهمان با شماره‌ی از‌قبل‌ثبت‌شده (بدون merge خودکار)، استفاده‌ی
دوباره از توکن مصرف‌شده، و تنظیم رمز عبور از صفحه‌ی سفارش.

## محدودیت این پچ

بدون PHP/Composer اجرا‌پذیر نوشته شده:

    php artisan route:list --name=website
    php artisan test --filter=GuestPurchaseFlowTest
    php artisan test --filter=GuestCheckoutTokenTest   # مطمئن شوید Refactor پوشه چیزی نشکسته
    php artisan test --filter=CheckoutFlowTest          # مسیر مشترکی که این پچ به آن redirect می‌کند

## قدم بعدی

پچ ۳.۲.۵: Telegram-linking (Login Widget HMAC واقعی، Review امنیتی
طبق بند ۱۰ به عهده‌ی نفر ۴ خواهد بود، نه من).
