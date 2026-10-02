# فاز W6 (بخش چهارم) - Audit (v3.2.9)

مرجع: ROADMAP-WEBSITE-v1.md فاز W6 بند 4 + بند 58 زیرسند + بند 100 سند مادر.

Roadmap صریح گفته: «همان Audit Log موجود در Core را صدا می‌زنند،
Website چیزی جدید ثبت نمی‌کند». این پچ دقیقا همین را انجام داد - با
یک اصلاح ضروری در همان یک نقطه‌ی مشترک.

## چیزی که از قبل رایگان پوشش داده شده بود

`PaymentService` (Core) از قبل در چند نقطه `AuditService::record()` را
صدا می‌زند. چون `ChargeController`/`WebsitePurchaseFacade` (پچ 3.2.2)
دقیقا همین `PaymentService` را صدا می‌زنند، **Audit پرداخت (Payment
Confirmation) از قبل برای Website هم رایگان پوشش داده شده** - هیچ
تغییری لازم نبود.

«Refund request»: Website هنوز هیچ قابلیت Refund ای ندارد (در هیچ‌کدام
از پچ‌های 3.2.1 تا 3.2.8 ساخته نشده)، پس چیزی برای Audit کردن وجود
ندارد - N/A، نه یک نقص.

## گپ واقعی که پیدا شد و رفع شد

`AuditService::resolveActor()` فقط Admin/Reseller را می‌شناخت. اگر از
یک اقدام مشتری Website (مثلا Identity Linking) صدا زده می‌شد، چون هیچ
admin/reseller ای لاگین نیست، actor به‌غلط 'system' ثبت می‌شد - یعنی
سابقه‌ی Audit نمی‌گفت واقعا «چه کسی» این کار را کرده.

### تغییرات

1. `AuditService`: نوع `$actor` به `User` گسترش یافت؛ `resolveActor()`
   حالا یک `User` (چه صریح داده شده، چه از طریق `Auth::guard('web')`)
   را به `actor_type='customer'` نگاشت می‌کند.
2. Migration جدید: ستون `audit_logs.actor_type` از ENUM محدود
   (`admin`,`reseller`,`system`) به یک `string` ساده تغییر کرد - چون
   ENUM باید مقدار `customer` را هم قبول کند، و تغییردادن مقادیر ENUM
   در MySQL هزینه‌اش با رفتن به string یکسان است (هر دو نیاز به
   بازنویسی تعریف ستون دارند).
3. سه نقطه‌ی Audit جدید اضافه شد (هر سه از طریق همان یک
   `AuditService::record()` موجود، نه یک مکانیزم جدید):
   - `TelegramLinkController`: `identity.telegram_linked` (موفق) و
     `identity.telegram_link_rejected_owned_by_other` (رد شده - بند
     87 سند مادر).
   - `GuestPurchaseController`: `identity.guest_account_created`
     (ساخت User ناقص از خرید مهمان) و
     `identity.guest_collision_detected` (تلاش با شماره/ایمیل
     متعلق به کاربر دیگر).

### محدودیت شناخته‌شده

`identity.guest_collision_detected` قبل از هر Auth ای اتفاق می‌افتد
(بازدیدکننده هنوز هیچ هویتی ندارد)، پس `actor_type` آن `system` ثبت
می‌شود - `AuditService` فعلا هیچ actor_type ای برای «بازدیدکننده‌ی
ناشناس» ندارد. این یک محدودیت مستند‌شده است، نه یک باگ؛ اضافه‌کردن یک
actor_type چهارم («anonymous») نیاز به تصمیم جدا دارد که آیا IP/
User-Agent در چنین سابقه‌ای قابل‌قبول است یا نه (که بند 10 زیرسند
درباره‌ی Guest Data محدودیت گذاشته).

## تست

`tests/Feature/Website/AuditLoggingTest.php` - چهار مسیر (ساخت حساب
مهمان، تصادم، لینک موفق تلگرام، لینک رد‌شده) + یک تست مستقیم روی
`AuditService::resolveActor()` برای اثبات این‌که به‌صورت خودکار
(بدون actor صریح) هم مشتری لاگین‌شده را درست تشخیص می‌دهد.

## محدودیت این پچ

بدون PHP اجرا‌پذیر نوشته شده:

    php artisan migrate
    php artisan test --filter=AuditLoggingTest
    php artisan test --filter=PaymentServiceTest

نکته‌ی مهم برای Migrate واقعی: اگر دیتابیس Production از قبل ردیف‌های
`actor_type='admin'` دارد، تبدیل ENUM به string هیچ داده‌ای را
نمی‌شکند (مقادیر موجود همان رشته‌ها می‌مانند)؛ فقط محدودیت سطح دیتابیس
برداشته می‌شود، اعتبارسنجی مقدار مجاز حالا فقط سطح اپلیکیشن
(`resolveActor()`) است.
