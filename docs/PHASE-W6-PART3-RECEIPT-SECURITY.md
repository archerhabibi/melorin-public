# فاز W6 (بخش سوم) - Receipt Upload Security (v3.2.8)

مرجع: ROADMAP-WEBSITE-v1.md فاز W6 بند 3 + بند 60 زیرسند + جدول بخش 9.6.

## آنچه از قبل درست بود (پچ 3.2.2)

بند 60 می‌خواهد: «Storage خصوصی + سروِ کنترل‌شده، نه public disk
مستقیم». این از پچ 3.2.2 (نفر 1، قبل از تقسیم‌کار رسمی) از قبل درست
پیاده شده بود:

- فایل روی دیسک local (خصوصی، storage/app/private) ذخیره می‌شود، نه public.
- تنها راه دیدنش، TelegramReceiptController پشت auth:admin است - هیچ
  URL عمومی مستقیمی وجود ندارد.

این پچ (3.2.8) آن معماری را تایید می‌کند و فقط چیزهایی را اضافه
می‌کند که واقعا جا افتاده بودند.

## چه چیزی اضافه/اصلاح شد

1. مطابقت با بخش 9.6 (حداکثر 5 مگابایت، فقط jpg/png/webp/pdf):
   - سقف حجم از 4 مگابایت (اشتباه پچ 3.2.2) به 5 مگابایت.
   - pdf که کلا جا افتاده بود، اضافه شد (هم Validation، هم accept سمت Client).
   - mimes: (نه extensions:) همچنان استفاده می‌شود - این Rule را
     Laravel با finfo (بررسی واقعی بایت‌های فایل) اعتبارسنجی می‌کند،
     نه فقط پسوند نام فایل.

2. دفاع در برابر Stored Content-Type XSS در TelegramReceiptController:
   - Content-Type پاسخ فقط اگر داخل یک فهرست مجاز (image/jpeg,
     image/png, image/webp, application/pdf) باشد عینا استفاده
     می‌شود؛ وگرنه application/octet-stream.
   - هدر X-Content-Type-Options: nosniff روی هر دو مسیر (رسید سایت و
     رسید تلگرام) اضافه شد.
   - نوع محتوای برگشتی از Telegram API قبل از مقایسه Normalize
     می‌شود (پسوند charset حذف می‌شود) تا رسیدهای معتبر تلگرام به غلط
     force-download نشوند.

## چرا این تغییرات خارج از پوشه‌ی اختصاصی من هستند

ReceiptController و TelegramReceiptController مالکیت انحصاری کسی
نیستند، اما این دو تغییر مستقیما سخت‌سازی امنیتی‌اند و بسیار محدود/
نقطه‌ای‌اند - نه بازنویسی منطق کسی دیگر.

## تست

tests/Feature/Website/ReceiptSecurityTest.php - پذیرش PDF، رد فایل
بزرگتر از 5 مگابایت، رد یک فایل با پسوند jpg ولی محتوای غیر تصویری.

## خارج از Scope این پچ

- Audit trail برای اینکه چه کسی چه رسیدی را دید - بخش بند 4، پچ 3.2.9.
- Virus/Malware scanning روی فایل آپلودی - Roadmap چنین چیزی نخواسته.

## محدودیت این پچ

بدون PHP اجرا‌پذیر نوشته شده:

    php artisan test --filter=ReceiptSecurityTest
    php artisan test --filter=WalletChargeFlowTest
