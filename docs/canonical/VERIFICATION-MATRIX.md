# Melorin — Verification Matrix

**Contract:** Master 2.8 · Website 1.8 · **Code baseline:** Release 3.3.0 (Git `09009bc`، working tree تمیز)

**راهنما:** ✅ انجام و مستند · ⚠️ ناقص یا **مغایر Contract** · ☐ انجام نشده · — نامربوط · ⛔ DEPRECATED

**قاعده‌ی صداقت:** ستون *Unit/Feature* فقط وقتی ✅ است که تست‌ها روی یک محیط واقعی اجرا و سبز شده باشند. مبنای فعلی، گزارش Release 3.3.0 است (۴۱۶ تست، همه سبز؛ ۹۹ تست Website) و **در فاز ۱ توسط من دوباره اجرا نشده** (محیط من PHP ندارد). قبل از Production باید روی محیط خودتان تکرار شود.

## ۱. ماتریس اصلی

| Feature | Contract | Code | Unit/Feature | E2E | Security | Staging | Production |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Main Purchase (Wallet) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Reseller Purchase + Double Debit | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Wallet (Ledger، Lock، Isolation) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| CustomerAccount (Lazy) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| **Guest Checkout (مدل جدید §3)** | ✅ | ✅ *(فاز ۴)* | ☐ *(نوشته شد، اجرا نشده)* | ☐ | ☐ | ☐ | ☐ |
| Pending → Login/Register → ادامه‌ی همان خرید | ✅ | ✅ *(فاز ۴)* | ☐ *(اجرا نشده)* | ☐ | ☐ | ☐ | ☐ |
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
| Email Verification + Gate (Purchase/Wallet Charge فقط) | ✅ | ✅ *(فاز ۴)* | ☐ *(اجرا نشده)* | ☐ | ☐ | ☐ | ☐ |
| Discount (No Stacking، Eligibility از سابقه) | ✅ | ☐ *(موتور وجود ندارد، C12)* | ☐ | ☐ | — | ☐ | ☐ |
| Guest Retention (۶۰ روز) | ✅ | ✅ *(`guest:prune` روزانه، فاز ۴)* | ☐ *(اجرا نشده)* | — | — | ☐ | ☐ |
| بازگشت به Checkout پس از شارژ (D-3) | ✅ | ✅ *(لینک بازگشت اضافه شد، فاز ۴)* | ☐ *(اجرا نشده)* | ☐ | — | ☐ | ☐ |
| Security Headers/CSP/Rate Limit | ⚠️ *Matrix رسمی ندارد* | ✅ | ✅ | — | ☐ | ☐ | ☐ |
| Money Representation (بدون float، ارز قابل‌تنظیم) | ✅ *(M1–M8)* | ✅ *(فاز ۵)* | ☐ *(اجرا نشده)* | — | — | ☐ *(Migration روی کپی DB)* | ☐ |
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
| C1 | `GuestPurchaseController` User می‌سازد + `Auth::login` | `Channels/Website/.../Guest/GuestPurchaseController.php` | ✅ بسته شد (فاز ۴) |
| C2 | Guest Form باید: `email` الزامی و `name`/`phone` **اختیاری** شوند. اکنون برعکس است: `guest_name`/`guest_phone` الزامی (Validation، `GuestCheckoutService::start` که Exception می‌دهد، ستون‌های NOT NULL) و `guest_email` nullable. ویوی Pending هم name/phone را بی‌شرط نمایش می‌دهد | `GuestCheckoutController::store`، `GuestCheckoutService::start`، Migration جدید (ALTER)، `checkout-pending.blade.php` | ✅ بسته شد (فاز ۴) |
| C3 | Login/Register پس از Pending، خرید را ادامه نمی‌دهد (`url.intended` فقط برای حالت تصادم) | Auth Controllers | ✅ بسته شد (فاز ۴) |
| C4 | تست‌های Guest مدل قدیم را تأیید می‌کنند | `GuestPurchaseFlowTest`, `GuestE2ETest`, `GuestPostPurchaseE2ETest` | ✅ بسته شد (فاز ۴) |
| C7 | ~~Google Sign-In~~ ← **بسته شد** (D-6: از Release اول حذف شد؛ کد و Contract هم‌راستا) | — | — |
| C8 | `.env.example` `SESSION_DRIVER=file` ↔ config پیش‌فرض `database`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | `.env.example`, `config/session.php` | ✅ بسته شد (فاز ۴) |
| C9 | `/complete-profile` و `POST /guest-checkout/purchase` وابسته به مدل قدیم Guest | `routes/website.php` | ✅ بسته شد (فاز ۴) |
| C10 | Email Verification در Contract (G11) هست ولی در کد نیست: `User` بدون `MustVerifyEmail`، بدون Route/Notification، بدون Gate روی Checkout/Wallet Charge، بدون تست | Auth + `User` + Middleware + تست | ✅ بسته شد (فاز ۴) |
| C5 | `orders.status`/`payments.purpose` enum شامل مقادیر تاریخی | Migrations | 3 (مستندسازی؛ حذف نه) |
| C6 | استفاده‌ی گسترده از `float` در سرویس‌های مالی | `PaymentService`, `WalletService`, … | ✅ بسته شد (فاز ۵؛ تست‌ها هنوز اجرا نشده) |

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
| C11 | Job حذف Guest بعد از ۶۰ روز نیست (`routes/console.php` فقط `provisioning:retry-failed` دارد) | ✅ بسته شد (فاز ۴) |
| C12 | موتور Discount وجود ندارد؛ فقط Contract (DS1–DS5) | Feature مستقل |
| C13 | `PaymentCallbackController` هیچ Purchase اجرا نمی‌کند (✅ مطابق D-3) ولی view `payment.callback` هیچ لینک/Redirect به Checkout ندارد و تست صریحی نیست | ✅ بسته شد (فاز ۴) |
| C8 | `.env.example` هنوز `SESSION_DRIVER=file`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | ✅ بسته شد (فاز ۴) |
| C5 | enumهای تاریخی (`payments.purpose=order`، `orders.status=pending/paid/…`) فقط مستند شدند؛ حذف نه | مستند شد |

## ۶. فاز ۴ — نتیجه
C1، C2، C3، C4، C8، C9، C10، C11، C13 در **کد و تست‌های نوشته‌شده** بسته شدند (جزئیات: `PHASE-4-GUEST-CLEANUP.md`). **هیچ تستی اجرا نشد** (PHP در محیط من نیست)؛ ستون Unit/Feature تا اجرای واقعی سبز ☐ می‌ماند. C6 (float → Integer Minor Unit) در فاز ۵ می‌ماند.
