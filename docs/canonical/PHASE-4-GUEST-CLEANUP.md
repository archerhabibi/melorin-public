# فاز ۴ — Guest Cleanup + Email Verify + Retention

**مبنا:** Master 2.7 · Website 1.7 · Release 3.3.0 (پس از فاز ۳)
**⚠️ هیچ تستی اجرا نشد** (محیط من PHP ندارد؛ فقط بررسی تعادل کروشه/پرانتز فایل‌های PHP انجام شد). تست‌ها نوشته شده‌اند ولی «TESTED» نیستند. اول `php artisan migrate && php artisan test` را روی محیط خودتان اجرا و نتیجه را بفرستید.

## ۱. شکاف‌های بسته‌شده

| شکاف | اقدام |
|---|---|
| C1، C9 | `GuestPurchaseController` و route `POST /guest-checkout/purchase` **حذف**. `/complete-profile` حذف؛ جایگزین: `/profile` (فقط اتصال Telegram، `ProfileController`). CTA «تکمیل حساب» از `order-show` برداشته شد. |
| C2 | فرم/Service/Migration: `guest_email` الزامی (NOT NULL، lowercase)، `guest_name`/`guest_phone` اختیاری. ویوی Pending فقط فیلدهای موجود را نشان می‌دهد. |
| C3 | Register/Login بعد از Pending همان Checkout را ادامه می‌دهند (`GuestCheckoutContinuation`)؛ Register فرم را پیش‌پر می‌کند (فقط راحتی، نه Proof). نشست Guest فقط پس از خرید واقعی همان Product مصرف می‌شود (`consume` اتمیک). |
| G7 | تصادم email/phone با User موجود در همان لحظه‌ی فرم Guest: Audit `identity.guest_collision_detected` + هدایت به Login؛ بدون Merge/Login خودکار. |
| C10 | `User implements MustVerifyEmail`، Routeهای `verification.*`، صفحه‌ی تأیید + ارسال مجدد، ارسال لینک در Register. `EmailVerificationGate` **داخل Core**: `PurchaseService::purchase` و `PaymentService::initiate`. کنترلرها فقط UX (redirect به صفحه‌ی تأیید، `url.intended` برای بازگشت). |
| C11 | `guest:prune` (روزانه ۰۳:۳۰): حذف consumed/expired/pending‌ِ گذشته > ۶۰ روز + Audit `system.guest_checkouts_pruned` با تعداد. |
| C13 | کارت شارژ از Checkout با `?product=` می‌آید؛ صفحه‌ی callback پس از شارژ **موفق** لینک «بازگشت به تکمیل خرید» دارد (URL از Session سمت سرور؛ product باید در همان Store قابل‌مشاهده باشد). خرید خودکار نمی‌شود. |
| C8 | `.env.example`: `SESSION_DRIVER=database`، `SESSION_LIFETIME=120`، `SESSION_SECURE_COOKIE=true`؛ هشدار MAIL. |

## ۲. فایل‌ها
**جدید:** Migration `2026_09_30_000001_guest_checkouts_email_required`، `EmailVerificationGate`، `EmailNotVerifiedException`، `EmailVerificationController`، `ProfileController`، `GuestCheckoutContinuation`، ویوهای `auth/verify-email` و `identity/profile`، تست‌های `EmailVerificationTest`، `GuestRetentionTest`، `ChargeReturnToCheckoutTest`.
**بازنویسی:** `GuestCheckoutService`/Facade/Controller، ویوهای Guest، `GuestCheckoutTokenTest`، `GuestPurchaseFlowTest`، `GuestE2ETest`.
**حذف:** `GuestPurchaseController`، `CompleteProfileController`، `complete-profile.blade.php`، `GuestPostPurchaseE2ETest` (مدل DEPRECATED X1/X2/X4).
**اصلاح‌شده:** `RegisteredUserController`، `AuthenticatedSessionController`، `CheckoutController`، `ChargeController`، `PurchaseService`، `PaymentService`، `User`، `CoreErrorMapper`، `routes/website.php`، `routes/console.php`، `MainWebsiteE2ETest`، `ResellerGuestCheckoutTest`، `AuditLoggingTest`، `TelegramLinkingTest`، ویوهای checkout/charge/callback/register.

## ۳. تصمیم‌هایی که باید تأیید کنید
1. **دامنه‌ی Gate:** فقط User با Email ثبت‌شده و تأییدنشده مسدود است. Userهای ربات (بدون email) مشمول نیستند، وگرنه خرید ربات متوقف می‌شد. Master G11 می‌گوید «Bot/Admin مستثنا نیستند» ولی وضعیت User بدون email را تعریف نکرده؛ اگر می‌خواهید سخت‌گیرانه‌تر باشد، بگویید.
2. **Verify فقط Main:** لینک Email از Queue می‌آید و StoreContext ندارد؛ کاربر Reseller Store هم در Main تأیید می‌کند.
3. **تمدید (Renewal) و Retry Provisioning مشمول Gate نیستند** (سفارش/پول قبلی وجود دارد؛ Master فقط Purchase و Wallet Charge را گفته).
4. **حذف داده‌ی Migration:** ردیف‌های `guest_checkouts` بدون email حذف می‌شوند (نشست‌های ۴۵ دقیقه‌ای؛ هیچ FK مالی/هویتی به آن‌ها نیست). `down()` فقط best-effort است.
5. **Userهای قدیمیِ ساخته‌شده از Guest** (`joined_from = website_guest_checkout`، بدون رمز) در دیتابیس Production ممکن است باقی باشند؛ فقط از طریق «فراموشی رمز» با email می‌توانند وارد شوند. کوئری بررسی: `SELECT COUNT(*) FROM users WHERE joined_from='website_guest_checkout'`.
6. **Email واقعی پیش‌نیاز است:** با `MAIL_MAILER=log` هیچ کاربر جدیدی نمی‌تواند خرید کند. قبل از Staging SMTP تنظیم شود.
7. کاربران موجود Website با email و `email_verified_at = NULL` (ثبت‌نام‌های قبل از فاز ۴) بعد از Deploy از خرید/شارژ مسدود می‌شوند تا تأیید کنند. اگر نمی‌خواهید: یک بار `UPDATE users SET email_verified_at = NOW() WHERE email IS NOT NULL AND email_verified_at IS NULL` (تصمیم کسب‌وکاری؛ برای ثبت‌نام‌های ناشناس توصیه نمی‌شود).

## ۴. ریسک‌های شناخته‌شده / انجام‌نشده
- تست‌ها اجرا نشدند؛ ممکن است خطاهای جزئی (import، انتظار redirect، `assertSessionHas('url.intended')`، زمان‌بندی `guest:prune` در تست) ظاهر شوند. خروجی شکست‌ها را بفرستید تا رفع شوند.
- `AuthenticatedSessionController::store` با `redirect()->intended()` مقصد را اگر `url.intended` قبلاً هست حفظ می‌کند؛ اگر Guest Cookie و `url.intended` هر دو باشند، `url.intended` برنده است.
- Reseller Bot/Telegram Bot مسیر Purchase را از `PurchaseService` می‌گذرانند؛ Userهای ربات بدون email بی‌تأثیرند ولی یک Test صریح برای ربات با email تأییدنشده اضافه نشد.
- `CoreErrorMapper` پیام Gate را برای Website دارد؛ ربات‌ها استثنای `PurchaseNotAllowedException` را با پیام خودش می‌گیرند (بررسی UX نشده).
- C6 (float → Integer Minor Unit) و C12 (Discount) در این فاز نیستند. Review امنیتی Guest Token (Replay/Fixation) هنوز لازم است.

## ۵. ورودی فاز ۵
C6 با دامنه‌ی ۸۱ `(float)`؛ Contract Test برای M5.
