# فاز W7 + W8 — تست، Verification Matrix، Deploy (v3.2.13)

مرجع: ROADMAP-WEBSITE-v1.md فاز W7 + W8 + بخش 10 (نقشه‌ی مالکیت نفر 5).

از این پچ نقش «نفر 5» (تست، Verification Matrix، Deploy) را ایفا
می‌کنم. طبق نقشه‌ی مالکیت، فقط tests/Feature/Website/، جدول
Verification Matrix، و Checklist بند 98/131 را دست می‌زنم.

## اسکلت تست مشترک (دیر رسید، ولی رسید)

Roadmap صریح گفته این اسکلت باید از روز اول کنار نفرات 1 تا 4 می‌آمد.
چون نقش نفر 5 رسما بعد از نوشتن همه‌ی پچ‌های قبلی مشخص شد،
tests/Concerns/InteractsWithWebsiteFixtures.php این پچ اضافه شد -
جمع‌آوری الگوهای تکراری (makeProduct با پنل Sanaei، Http::fake پنل و
Zarinpal) که در حداقل 5 فایل تست قبلی هرکدام جدا نوشته شده بودند.
تست‌های قدیمی *بدون تغییر رفتار* به آن مهاجرت داده نشدند (ریسک
بی‌دلیل روی تست‌های از قبل سبز)؛ این یک بدهی فنی مستندشده است.

## سه E2E صریح Roadmap (از چهارتای بند 61-64)

- `MainWebsiteE2ETest` - ثبت‌نام تا سفارش کامل، بند 61.
- `GuestE2ETest` - بازدید ناشناس تا شروع خرید بدون هیچ حساب، بند 63.
- `GuestPostPurchaseE2ETest` - خرید مهمان تا Claim حساب تا ورود دوباره
  و دیدن همان سفارش، بند 64.
- **Reseller Website E2E (بند 62) مسدود است** - منتظر نفر 3.

## یک گپ واقعی پیدا و رفع شد (خارج از تست محض)

هنگام نوشتن Verification Matrix، نکته‌ی باز صریح پچ 3.2.12 (نفر 2:
«ثبت‌نام سایت هنوز ?ref= را نمی‌خواند») را بستم:
`RegisteredUserController` حالا `?ref={user_id}` را در Session نگه
می‌دارد و در لحظه‌ی ثبت‌نام، فقط اگر به یک User واقعی اشاره کند مصرف
می‌کند (عدد نامعتبر بی‌صدا نادیده گرفته می‌شود). تست:
`ReferralRegistrationTest`.

RegisteredUserController مالکیت انحصاری هیچ‌کس نیست (از W0/W1، قبل از
تقسیم‌کار)؛ این یک تغییر کوچک و کاملا افزایشی بود (یک ستون موجود روی
create، بدون تغییر منطق دیگری).

## Verification Matrix

`docs/history/VERIFICATION-MATRIX.md` - جدول کامل بند 104 پر شد: هر پانزده
ردیف با چهار وضعیت SPECIFIED/IMPLEMENTED/TESTED/PRODUCTION VERIFIED.
خلاصه:
- همه‌ی ردیف‌های Main (Guest Checkout، Login، Register،
  CustomerAccount، Wallet، Main Purchase، Payment، Card-to-Card،
  Orders، Provisioning، Renewal، Referral، Security): IMPLEMENTED.
- Reseller Purchase: جزئی (مسیرها Context-agnostic‌اند ولی تست
  صریح ندارند).
- Refund/Retry: N/A (تصمیم صریح: Admin-only).
- Reseller Management: پیاده نشده (منتظر نفر 3).
- **هیچ ردیفی TESTED واقعی (اجراشده) یا PRODUCTION VERIFIED نیست** -
  چون این محیط PHP اجرا‌پذیر ندارد. این صادقانه‌ترین چیزی است که
  می‌توانم بگویم؛ شما باید php artisan test را واقعا اجرا کنید.

## چک‌لیست Deploy (فاز W8)

`docs/operations/DEPLOY-CHECKLIST.md` - چک‌لیست کامل بند 73 + پنج
پیش‌نیاز مسدودکننده‌ی Production.

## محدودیت این پچ

بدون PHP اجرا‌پذیر نوشته شده:

    php artisan test
    (کل مجموعه، نه فقط فایل‌های جدید - دقیقا همان درسی که Roadmap از
    باگ پچ 3.1.9 یادآوری کرده)

نتیجه‌ی واقعی این اجرا، تنها چیزی است که جدول Verification Matrix را
از «نوشته شده» به واقعا TESTED می‌رساند.
