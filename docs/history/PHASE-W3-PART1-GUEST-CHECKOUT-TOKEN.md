# فاز W3 (بخش اول) — Guest Checkout Token (v3.2.3)

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W3، بند ۱ + بخش ۹.۳ (TBD Decision
Register) + `Melorin_Website_Architecture_Subdocument_v1_1.md` بندهای
۷، ۸، ۹، ۱۰ + `Melorin_Master_Architecture_Contract_v2_2.md` بند ۱۴۶.

پچ متناظر: `melorin-website-v3.2.3.patch`. **فقط بند ۱** از شش‌بندِ فاز
W3 است — دقیقاً همان چیزی که درخواست شد، نه کل فاز.

## چرا فقط بند ۱، نه کل فاز W3

Roadmap خودش فاز W3 را «سخت‌ترین و صریح‌ترین بخش دو سند» می‌نامد و شش
بند جدا می‌شمارد. بند ۱ (Guest Checkout Token) مستقل قابل‌ساخت است:
فقط یک جدول + یک سرویس + یک Endpoint عمومی. اما بند ۳ («Flow کامل تا
Payment→Order→Provisioning») به یک تصمیم معماری واقعی نیاز دارد که
نباید ضمنی گرفته شود:

> `PurchaseService::purchase()` امروز یک `CustomerAccount` الزامی
> می‌گیرد. بند ۷ زیرسند می‌گوید «CustomerAccount شرط لازم Guest
> Checkout نیست» — یعنی یا این امضا باید تغییر کند (پارامتر
> `CustomerAccount` اختیاری با یک مسیر جایگزین برای Guest)، یا باید
> یک CustomerAccount سبک/موقت پشت‌صحنه در لحظه‌ی پرداخت ساخته شود.
> این دو گزینه پیامدهای متفاوتی روی Wallet، Idempotency و گزارش‌های
> مالی دارند و باید صریح انتخاب شوند — دقیقاً همان درسی که خودِ سند
> در بند ۸۷ («merge خودکار بدون اثبات ممنوع») و بند ۹ («شکست Identity
> Resolution نباید خرید موفق را باطل کند») تاکید کرده: تصمیمات هویتی
> نباید ضمنی و در دل یک پچ دیگر گرفته شوند.

پس این پچ Guest را تا «نشست خرید با اطلاعات تماس معتبر» می‌برد، نه تا
خریدِ واقعی. بندهای ۲ تا ۶ (تکمیل Flow، Identity Resolution، Guest
Claiming Flow، تست Idempotency) به‌صراحت به پچ بعدی موکول شدند.

## چه چیزی ساخته شد

- Migration `guest_checkouts` — ستون‌ها دقیقاً محدود به بند ۱۰ زیرسند:
  `guest_name`, `guest_phone`, `guest_email` + `token` + `product_id`
  + `reseller_id` (nullable، برای Context) + `status` + `expires_at`.
  عمداً بدون IP/User-Agent (بند ۱۰: «به‌تنهایی Proof of Identity
  نیستند») و بدون `user_id`/`customer_account_id` (بند ۷: هویت موقت).
- `GuestCheckout` مدل.
- `GuestCheckoutService` — **در Core** (`app/Services/Core/Guest/`)،
  نه داخل کانال Website؛ دلیل در کامنت بالای خودِ کلاس: Guest یک مفهوم
  Core است (هم‌تراز با User/CustomerAccount) و فاز بعدی (Identity
  Resolution) به همین سرویس نیاز خواهد داشت، دقیقاً مثل IdentityService.
  - `start()`: می‌سازد، TTL=۴۵ دقیقه (بند ۹.۳: «۳۰-۶۰ دقیقه»، عدد دقیق
    در Implementation).
  - `findActive()`: اعتبارسنجی + انقضای lazy (بدون نیاز به Scheduled
    Job برای این حجم).
- `WebsiteGuestCheckoutFacade` — Adapter نازک، هم‌الگو با بقیه.
- `GuestCheckoutController` — تنها کنترلر Website که عمداً **بدون**
  `auth`/`store.customer` است:
  - `GET/POST /products/{id}/guest-checkout` — فرم شروع (بدون login).
  - `GET /guest-checkout/pending` — می‌خواند از Cookie، وضعیت را نشان
    می‌دهد.
- Cookie امضاشده (بند ۹.۳: «Guest Session ... در Cookie امضاشده») —
  از طریق `EncryptCookies` (گروه `web` که همین حالا هم روی همه‌ی
  route های Website هست) به‌صورت خودکار رمزنگاری+امضا می‌شود؛ هیچ
  پیاده‌سازی HMAC دستی لازم نبود.
- لینک «خرید به‌عنوان مهمان» در `product-show.blade.php`، کنار «برای
  خرید وارد شوید».
- تست HTTP: `tests/Feature/Website/GuestCheckoutTokenTest.php` — شروع
  بدون auth، الزامی‌بودن نام/تلفن (نه ایمیل)، خواندن از Cookie،
  توکن نامعتبر/منقضی، و ایزوله‌بودن توکن بین Main و Reseller.

## تصمیمات UX که سند مشخص نکرده (و این پچ گرفته)

- الزامی‌بودن `guest_name`/`guest_phone`، اختیاری‌بودن `guest_email`:
  سند سه فیلد را «نمونه» معرفی کرده (بند ۱۰: «نمونه: ...») نه با
  مشخصِ کدام الزامی است؛ برای یک VPN Market ایرانی، شماره تماس برای
  پشتیبانی ضروری‌تر از ایمیل است.
- TTL = ۴۵ دقیقه: سند صریحاً گفته «عدد دقیق در Implementation طبق UX
  نهایی تنظیم می‌شود، نه در سند» (بند ۹.۳) — قابل‌تغییر بدون Migration
  چون یک ثابت در سرویس است، نه ستون دیتابیس.

## خارج از Scope این پچ (عمدی)

- تکمیل پرداخت/سفارش/Provisioning برای Guest (بند ۳ فاز W3).
- Identity Resolution بعد از خرید (بند ۴) و Guest Claiming Flow (بند
  ۵) — هر دو منطقاً به بند ۳ وابسته‌اند.
- Guest-to-Telegram linking (بخش ۹.۳، ردیف آخر) — یک تصمیم امنیتی
  مستقل (Telegram Login Widget رسمی یا Deep-Link/کد یک‌بارمصرف) که
  باید جدا Review شود.

## محدودیت این پچ

مثل پچ‌های قبلی، بدون PHP/Composer اجرا‌پذیر نوشته شده:

    php artisan migrate
    php artisan route:list --name=guest-checkout
    php artisan test --filter=GuestCheckoutTokenTest

## قدم بعدی

بند ۳ فاز W3: تصمیم معماری برای اتصال Guest به `PurchaseService`
(گزینه‌های بالا) و تکمیل Flow تا Provisioning.
