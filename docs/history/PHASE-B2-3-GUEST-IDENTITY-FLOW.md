# B2.3 — Guest Checkout Identity Flow

**نسخه:** 3.3.11 · **Contract:** Master §3 (G3، G5، G6، G7، G9) و Website §5 — بدون تغییر.

## وضعیت قبل از B2.3 (از W3/فاز ۴ موجود بود)
Guest Form (email تنها الزامی)، Pending Page، Token در Cookie رمزنگاری‌شده، Cross-Store، ادامه‌ی همان خرید بعد از Login/Register/Google، تصادم ⇒ Login + Audit، Lazy CustomerAccount، Retention ۶۰ روزه.

## آنچه B2.3 اضافه/بهتر کرد
| # | بهبود | محل |
|---|---|---|
| 1 | نشست Pending قبلی با شروع نشست تازه باطل می‌شود | `GuestCheckoutService::start(replacing)` |
| 2 | لغو و شروع دوباره (`discard`) | `GuestCheckoutController::cancel`، route `guest-checkout.cancel` |
| 3 | کاربر واردشده Guest نمی‌شود | `GuestCheckoutController::show/store/pending` |
| 4 | تصادم Email بدون حساسیت به حروف | `GuestCheckoutService::detectCollision` + `EmailIdentity` |
| 5 | Audit مصرف/لغو (بدون PII) | `consume(guest, user)`، `discard` |
| 6 | Pending Page: مبلغ، زمان باقی‌مانده، Google، لغو؛ محصول ناموجود ⇒ 404 + باطل‌شدن | `checkout-pending.blade.php` |
| 7 | یادآور ادامه‌ی خرید روی Login/Register | `guest/continue-notice.blade.php` |
| 8 | یک منبع برای TTL و عمر Cookie | `GuestCheckoutService::TTL_MINUTES` |

## عمداً انجام نشد
- Login خودکار/Merge با تصادم Email (ممنوع، G7/R9).
- Prefill ایمیل Guest در فرم Login (Proof نیست؛ فقط Register پیش‌پر می‌شود).
- ساخت User/CustomerAccount از Guest (G3).
- نرمال‌سازی شماره‌ی تلفن برای تصادم: قالب‌های تلفن در دیتا یکدست نیست؛ نیازمند تصمیم (D-14 پیشنهادی).

## تأیید
`tests/Feature/Website/GuestIdentityFlowTest.php`. توجه: در محیط تولید patch، تست‌ها اجرا نشده‌اند؛ پیش از merge `php artisan test` اجرا شود.
