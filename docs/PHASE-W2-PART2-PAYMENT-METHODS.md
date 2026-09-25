# فاز W2 (بخش دوم) — Payment UI: Zarinpal + Card-to-Card (v3.2.2)

مرجع: `ROADMAP-WEBSITE-v1.md` (فاز W2 بند ۵: «Wallet Payment + Zarinpal
+ Card-to-Card»، بند ۶۳: «همه از قبل در Core پیاده و تست شده‌اند؛
Website فقط UI آن‌ها را می‌سازد») + `docs/PHASE-W2-COMMERCE-CORE.md`
(«قدم بعدی» همان سند).

پچ متناظر: `melorin-website-v3.2.2.patch`. ادامه‌ی بی‌واسطه‌ی 3.2.1.

## تصمیم معماری اصلی این پچ

`PaymentService::initiate()` فقط `purpose='wallet_charge'` می‌پذیرد —
طبق کامنت خودِ آن متد: «خرید هیچ‌وقت Payment نمی‌سازد؛ خرید مستقیماً
از Wallet کسر می‌شود». پس Zarinpal/Card-to-Card در سایت **مستقیماً به
یک سفارش وصل نیستند** — فقط کیف‌پول را شارژ می‌کنند. جریان کامل:

    Checkout (موجودی کافی نیست) → «شارژ کیف پول» → انتخاب مبلغ/روش
    → [Zarinpal: هدایت به درگاه] یا [Card-to-Card: آپلود رسید]
    → (بعد از تایید) → کاربر خودش به Checkout برمی‌گردد و «پرداخت از
      کیف پول» را می‌زند.

«شارژ خودکار + ادامه‌ی خودکار خرید» عمداً پیاده نشده — نگه‌داشتن نیّتِ
خرید بین دو درخواست HTTP یک تصمیم امنیتی/معماری جداست که در Roadmap
فعلی مشخص نشده؛ اضافه‌کردنش بدون تصمیم صریح دقیقاً همان چیزی است که
بند ۱ Roadmap («بدون Business Logic مستقل در Channel») منع می‌کند.

## چه چیزی ساخته شد

- `WebsiteChargeFacade` — Adapter نازک روی `PaymentService::initiate()`
  (`purpose='wallet_charge'`, `walletOwnerType='user'`)، هم‌الگو با
  سه Facade قبلی.
- `ChargeController` — `GET/POST /wallet/charge`: انتخاب مبلغ (حداقل
  ۱۰,۰۰۰ تومان) + روش فعال؛ بعد از POST، Zarinpal → `redirect()->away()`
  به درگاه، Card-to-Card → صفحه‌ی رسید.
- `ReceiptController` — `GET/POST /wallet/charge/{payment}/receipt`:
  نمایش شماره‌کارت/صاحب‌حساب و آپلود رسید. مالکیت با همان
  `Payment::findPendingForReceipt()` سنجیده می‌شود که ربات از قبل
  استفاده می‌کند (P1 گزارش امنیتی #12) — **نه** یک بررسی مالکیت موازی.
- **تغییر روی کد مشترک (خارج از کانال Website):**
  `app/Http/Controllers/Admin/TelegramReceiptController.php` طوری
  گسترش داده شد که هم `receipt_image` تلگرام (file_id) و هم رسید
  آپلودشده از سایت (پیشوند `website:`، ذخیره‌شده روی دیسک خصوصی
  `local`) را نمایش دهد. این تغییر عمداً این‌جا مستند شده چون خلاف
  اصل «هیچ کانالی کد مشترک را دوباره نمی‌نویسد» به‌نظر می‌رسد — اما
  جایگزینش (نوشتن یک Endpoint نمایش رسید جداگانه برای سایت) دقیقاً
  همان تکرار کد بود؛ گسترش‌دادن Endpoint مشترک ادمین، نه دوباره‌نویسی،
  انتخاب درست‌تر بود.
- Route/View جدید (`wallet.charge.show/store`,
  `wallet.receipt.show/store`) برای Main و Reseller؛ لینک «شارژ کیف
  پول» در `checkout-show.blade.php` جایگزین متن placeholder پچ ۳.۲.۱ شد.
- تست HTTP: `tests/Feature/Website/WalletChargeFlowTest.php` —
  ریدایرکت Zarinpal (با `Http::fake`، هم‌الگو با
  `tests/Feature/PaymentServiceTest.php` موجود)، مسیر Card-to-Card تا
  آپلود رسید، و عدم دسترسی به رسید/پرداخت کاربر دیگر.

## خارج از Scope این پچ (عمدی)

- تایید/رد Payment از پنل ادمین (`confirmManual`) — از قبل در Core
  هست، Website چیزی به آن اضافه نمی‌کند (طبق بند ۶۳).
- ادامه‌ی خودکار خرید بعد از شارژ موفق — بالا توضیح داده شد.
- فهرست تاریخچه‌ی شارژها/پرداخت‌های کاربر — بخشِ فاز W4 (Wallet
  Context، بند ۳۰/۳۱ Roadmap)، نه این پچ.

## محدودیت این پچ — مهم

مثل پچ‌های قبلی، در محیطی بدون PHP/Composer اجرا‌پذیر نوشته شده. قبل
از merge:

    php artisan route:list --name=website
    php artisan test --filter=WalletChargeFlowTest
    php artisan test --filter=PaymentServiceTest   # مطمئن شوید تغییر TelegramReceiptController چیزی را نشکسته

و یک تست دستی End-to-End روی Zarinpal Sandbox (نه فقط Http::fake) قبل
از فعال‌کردن merchant_id واقعی در Production.

## قدم بعدی

فاز W2 عملاً کامل شد (Checkout + Wallet + Zarinpal + Card-to-Card +
Order Display). قدم بعدی طبق ترتیب Roadmap: فاز W3 (Guest Checkout).
