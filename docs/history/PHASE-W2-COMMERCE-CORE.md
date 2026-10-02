# فاز W2 (بخشی) — Commerce هسته‌ای: Checkout بدون Cart + Wallet Payment (v3.2.1)

مرجع: `ROADMAP-WEBSITE-v1.md` (فاز W2) + `Melorin_Website_Architecture_Subdocument_v1_1.md`
+ TBD Decision Register (بخش ۹ همان سند) + `docs/history/PHASE-W0-W1-WEBSITE-CHANNEL.md`
(«قدم بعدی» همان سند).

پچ متناظر: `melorin-website-v3.2.1.patch`.

## چه چیزی ساخته شد

- `WebsitePurchaseFacade` — Adapter نازک روی `PurchaseService::purchase()`،
  دقیقاً هم‌الگو با `WebsiteCatalogFacade`/`WebsiteWalletFacade`؛ صفر منطق
  تصمیم‌گیری، فقط `salesChannel = 'website'` را ثابت می‌کند.
- `CheckoutController` — `GET /products/{id}/checkout` (صفحه‌ی تایید،
  ساخت توکن Idempotency تازه هر بار) و `POST` همان مسیر (اجرای خرید).
  Checkout مستقیم بدون Cart طبق بند ۳ فاز W2؛ فقط Wallet Payment (بند
  ۵) — Zarinpal و Card-to-Card عمداً در این پچ نیستند، به دلیل زیر
  «خارج از Scope این پچ» توضیح داده شده.
- `OrderController::show` — نمایش تک‌سفارش بعد از Checkout + وضعیت
  Provisioning (بند ۶ و ۸)، با بررسی مالکیت (`customer_account_id`) و
  Context (`reseller_id`) — هر دو، نه فقط یکی، تا سفارش نماینده‌ی A از
  فروشگاه اصلی هم قابل مشاهده نباشد.
- Route جدید: `checkout.show`, `checkout.store` (`throttle:10,1` طبق
  تصمیم ۹.۶ — این یک مسیر مالی است)، `orders.show`، هم برای Main هم
  برای Reseller (همان الگوی `registerSharedRoutes` موجود، بدون تکرار).
- View: `checkout-show.blade.php`، `order-show.blade.php`؛ ویرایش
  `product-show.blade.php` برای فعال‌کردن دکمه‌ی خرید (که در پچ ۳.۲.۰
  عمداً placeholder بود).
- Idempotency (بند ۷): از UUID تولیدشده در `GET checkout` به‌عنوان
  hidden field استفاده می‌شود، دقیقاً همان الگوی «توکن فرم سایت» که
  Roadmap صریحاً پیشنهاد داده — با پیشوند `website:` به
  `PurchaseService::purchase($idempotencyKey)` پاس داده می‌شود.
- تست HTTP جدید: `tests/Feature/Website/CheckoutFlowTest.php` — مسیر
  کامل مهمان→ریدایرکت به login، خرید موفق کیف‌پول، Idempotency (ارسال
  دوباره‌ی همان توکن دو بار شارژ نمی‌کند)، موجودی ناکافی، و عدم دسترسی
  به سفارش کاربر دیگر.

## خارج از Scope این پچ (عمدی)

- **Zarinpal و Card-to-Card** (بند ۵ فاز W2، مورد ۲ و ۳): هر دو نیاز
  به درگاه واقعی/callback واقعی دارند که در همین sandbox قابل تست
  end-to-end نیست؛ اضافه‌کردن هر دو بدون تست واقعی دقیقاً همان خطایی
  است که بند ۷ فاز W7 می‌خواهد جلویش گرفته شود («IMPLEMENTED کافی
  نیست»). پیشنهاد: هرکدام یک پچ کوچک مستقل بعدی، چون `CheckoutController`
  از قبل به‌شکلی طراحی شده که افزودن یک متد پرداخت جدید = افزودن یک
  حالت، نه بازنویسی.
- **فهرست کامل سفارش‌ها** («Orders»، بند ۳۲ زیرسند) — طبق Roadmap این
  بخشِ فاز W4 است، نه W2؛ این پچ فقط صفحه‌ی تک‌سفارشیِ بعد از Checkout
  را می‌سازد.
- **Guest Checkout** — فاز W3 مستقل است؛ `CheckoutController` فعلی
  عمداً پشت `auth` + `store.customer` است.

## محدودیت این پچ — مهم، حتماً قبل از merge بخوانید

این کد در یک محیط sandbox **بدون PHP/Composer قابل‌اجرا** نوشته شده
(دقیقاً همان محدودیتی که `docs/history/PHASE-W0-W1-WEBSITE-CHANNEL.md` هم به
آن اشاره کرده بود). یعنی:

- `composer install`، `php artisan migrate`، `php artisan route:list`
  و `php artisan test` **اجرا نشده‌اند**.
- تست‌های `CheckoutFlowTest` نوشته شده‌اند ولی طبق همان درسی که خودِ
  این پروژه از باگ پچ ۳.۱.۹ گرفت («نوشتنِ تست بدون اجرا کافی نیست»)،
  این پچ را نباید IMPLEMENTED نهایی حساب کرد — وضعیتش دقیقاً
  **IMPLEMENTED، نه TESTED** (طبق ستون‌های بند ۱۰۴ زیرسند) تا وقتی که
  روی محیطی با PHP/MySQL واقعی اجرا شود.

**قبل از merge، حتماً روی Staging:**
1. `composer dump-autoload` (کلاس‌های جدید Facade/Controller).
2. `php artisan route:list --name=website` — بررسی نبود تداخل با
   مسیرهای پنل نماینده Filament (همان ریسکی که پچ ۳.۲.۰ هم داشت).
3. `php artisan test --filter=CheckoutFlowTest`.
4. یک خرید دستی واقعی از UI با کاربر تستی، هم Main هم یک فروشگاه
   نماینده‌ی فعال.

## قدم بعدی

فاز W2 باقی‌مانده: انتخاب/افزودن Zarinpal (Direct Payment) و
Card-to-Card به همان `CheckoutController`. سپس فاز W3 (Guest Checkout)
طبق ترتیب Roadmap.
