# Melorin — Verification Matrix

**Contract:** Master 2.7 · Website 1.6 · **Code baseline:** Release 3.3.0 (Git `09009bc`، working tree تمیز)

**راهنما:** ✅ انجام و مستند · ⚠️ ناقص یا **مغایر Contract** · ☐ انجام نشده · — نامربوط · ⛔ DEPRECATED

**قاعده‌ی صداقت:** ستون *Unit/Feature* فقط وقتی ✅ است که تست‌ها روی یک محیط واقعی اجرا و سبز شده باشند. مبنای فعلی، گزارش Release 3.3.0 است (۴۱۶ تست، همه سبز؛ ۹۹ تست Website) و **در فاز ۱ توسط من دوباره اجرا نشده** (محیط من PHP ندارد). قبل از Production باید روی محیط خودتان تکرار شود.

## ۱. ماتریس اصلی

| Feature | Contract | Code | Unit/Feature | E2E | Security | Staging | Production |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Main Purchase (Wallet) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Reseller Purchase + Double Debit | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Wallet (Ledger، Lock، Isolation) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| CustomerAccount (Lazy) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| **Guest Checkout (مدل جدید §3)** | ✅ | ⚠️ *مغایر* | ⚠️ *تست‌ها مدل قدیم را تأیید می‌کنند* | ☐ | ☐ | ☐ | ☐ |
| Pending → Login/Register → ادامه‌ی همان خرید | ✅ | ⚠️ *کد User می‌سازد و Login می‌کند* | ⚠️ | ☐ | ☐ | ☐ | ☐ |
| Payment State Machine (۴ وضعیت) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Payment Purpose (`wallet_charge` فقط) | ✅ | ✅ | ✅ | ☐ | — | ☐ | ☐ |
| Direct Payment = Wallet Charge (Zarinpal) | ✅ | ✅ | ✅ *(Http::fake)* | ☐ | ☐ | ☐ *(Sandbox واقعی لازم)* | ☐ |
| Card-to-Card + Receipt | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Provisioning + Attempts | ✅ | ✅ | ✅ | ☐ | — | ☐ | ☐ |
| Retry بدون Debit مجدد | ✅ | ✅ | ✅ | ☐ | — | ☐ | ☐ |
| Provisioning (پنل واقعی، Timeout، پاسخ گمشده) | ✅ | ✅ | ⚠️ *Mock* | ☐ | — | ☐ | ☐ |
| Refund (Snapshot) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Renewal | ✅ | ✅ | ✅ | ☐ | — | ☐ | ☐ |
| Referral / Commission | ✅ | ✅ | ✅ | ☐ | — | ☐ | ☐ |
| Sale Limit / Capacity (Atomic) | ✅ | ✅ | ✅ *(SQLite تک‌پردازه)* | ☐ *(Concurrency واقعی روی MySQL)* | — | ☐ | ☐ |
| Reseller Isolation (Main / A / B) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Reseller Website + Branding + Management | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Telegram Linking | ✅ | ✅ | ✅ | ☐ | ⚠️ *Self-audit فقط* | ☐ | ☐ |
| Email Verification + Gate (Purchase/Wallet Charge فقط) | ✅ | ☐ *(C10)* | ☐ | ☐ | ☐ | ☐ | ☐ |
| Discount (No Stacking، Eligibility از سابقه) | ✅ | ☐ *(موتور وجود ندارد، C12)* | ☐ | ☐ | — | ☐ | ☐ |
| Guest Retention (۶۰ روز) | ✅ | ☐ *(C11)* | ☐ | — | — | ☐ | ☐ |
| بازگشت به Checkout پس از شارژ (D-3) | ✅ | ⚠️ *Purchase خودکار نیست ✅، ولی صفحه‌ی callback لینک بازگشت ندارد (C13)* | ☐ | ☐ | — | ☐ | ☐ |
| Security Headers/CSP/Rate Limit | ⚠️ *Matrix رسمی ندارد* | ✅ | ✅ | — | ☐ | ☐ | ☐ |
| Money Representation (بدون float) | ✅ *(M1–M5؛ واحد تومان)* | ⚠️ *۸۱ مورد `(float)`* | — | — | — | — | — |
| Backup / Restore | ☐ | ✅ *(update-git.sh)* | — | — | — | ☐ | ☐ |
| Rollback (Migration/Health failure عمدی) | ☐ | ✅ | — | — | — | ☐ | ☐ |
| Observability / Alerting / Health | ☐ | ⚠️ | — | — | — | ☐ | ☐ |
| Independent Security Review | — | — | — | — | ☐ | — | — |

## ۲. موارد DEPRECATED (نباید دوباره پیاده شوند)

| ⛔ | مورد |
|---|---|
| X1/X2 | Guest بدون Login خرید نهایی می‌کند + Post-Purchase Identity Resolution |
| X3 | Provisional/Unverified CustomerAccount برای Guest |
| X4 | ساخت خودکار User از Guest + Auto-Login |
| X5 | `purpose = order` |
| X6/X7 | Direct Payment → Confirmation → Purchase و Payment↔Order |
| X8 | Payment States `created / processing / partially_refunded` |

## ۳. شکاف‌های Code ↔ Contract (ورودی فازهای بعد)

| # | شکاف | فایل/محل | فاز |
|---|---|---|---|
| C1 | `GuestPurchaseController` User می‌سازد + `Auth::login` | `Channels/Website/.../Guest/GuestPurchaseController.php` | 4 |
| C2 | Guest Form باید: `email` الزامی و `name`/`phone` **اختیاری** شوند. اکنون برعکس است: `guest_name`/`guest_phone` الزامی (Validation، `GuestCheckoutService::start` که Exception می‌دهد، ستون‌های NOT NULL) و `guest_email` nullable. ویوی Pending هم name/phone را بی‌شرط نمایش می‌دهد | `GuestCheckoutController::store`، `GuestCheckoutService::start`، Migration جدید (ALTER)، `checkout-pending.blade.php` | 4 |
| C3 | Login/Register پس از Pending، خرید را ادامه نمی‌دهد (`url.intended` فقط برای حالت تصادم) | Auth Controllers | 4 |
| C4 | تست‌های Guest مدل قدیم را تأیید می‌کنند | `GuestPurchaseFlowTest`, `GuestE2ETest`, `GuestPostPurchaseE2ETest` | 4 |
| C7 | ~~Google Sign-In~~ ← **بسته شد** (D-6: از Release اول حذف شد؛ کد و Contract هم‌راستا) | — | — |
| C8 | `.env.example` `SESSION_DRIVER=file` ↔ config پیش‌فرض `database`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | `.env.example`, `config/session.php` | 3 |
| C9 | `/complete-profile` و `POST /guest-checkout/purchase` وابسته به مدل قدیم Guest | `routes/website.php` | 4 |
| C10 | Email Verification در Contract (G11) هست ولی در کد نیست: `User` بدون `MustVerifyEmail`، بدون Route/Notification، بدون Gate روی Checkout/Wallet Charge، بدون تست | Auth + `User` + Middleware + تست | 4/5 |
| C5 | `orders.status`/`payments.purpose` enum شامل مقادیر تاریخی | Migrations | 3 (مستندسازی؛ حذف نه) |
| C6 | استفاده‌ی گسترده از `float` در سرویس‌های مالی | `PaymentService`, `WalletService`, … | 5 |

## ۴. Release Artifact (یافته‌های ZIP دریافتی)

| مورد | وضعیت |
|---|---|
| VERSION = Git HEAD = نام Release (3.3.0) | ✅ در ZIP فعلی |
| Working Tree تمیز | ✅ |
| `.env` داخل ZIP | ⚠️ (در `.gitignore` است ولی در ZIP هست) → Rotate + حذف از Artifact |
| `melorin-backup-2026-09-01.sql` داخل ZIP | ⚠️ حذف از Artifact |
| `webhook.json` (Token/Secret) داخل ZIP | ⚠️ Rotate + حذف |
| `vendor/` و `node_modules/` داخل ZIP | ⚠️ مدل Artifact باید مشخص شود |

## ۵. فاز ۳ — شکاف‌های جدید
| # | شکاف | فاز |
|---|---|---|
| C11 | Job حذف Guest بعد از ۶۰ روز نیست (`routes/console.php` فقط `provisioning:retry-failed` دارد) | 4 |
| C12 | موتور Discount وجود ندارد؛ فقط Contract (DS1–DS5) | Feature مستقل |
| C13 | `PaymentCallbackController` هیچ Purchase اجرا نمی‌کند (✅ مطابق D-3) ولی view `payment.callback` هیچ لینک/Redirect به Checkout ندارد و تست صریحی نیست | 4 |
| C8 | `.env.example` هنوز `SESSION_DRIVER=file`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | 4 (اصلاح فایل) |
| C5 | enumهای تاریخی (`payments.purpose=order`، `orders.status=pending/paid/…`) فقط مستند شدند؛ حذف نه | مستند شد |
