# Melorin — Verification Matrix

**Contract:** Master 2.8 · Website 1.8 · **Code baseline:** 3.3.5 (تکمیل فاز ۷ — Baseline + Redis)

**راهنما:** ✅ انجام و مستند · ⚠️ ناقص یا **مغایر Contract** · ☐ انجام نشده · — نامربوط · ⛔ DEPRECATED

**قاعده‌ی صداقت:** ستون *Unit/Feature* فقط وقتی ✅ است که تست‌ها روی یک محیط واقعی اجرا و سبز شده باشند. آخرین اجرا (تکمیل فاز ۷، 3.3.5): `php artisan test` ← **SQLite: ۴۷۷ سبز (+۵ تست Redis با flag = ۴۸۲)؛ MariaDB 10.11 + Redis 7: ۴۸۲ سبز، ۰ skip** (PHP 8.3 لینوکس، `migrate:fresh` بدون خطا). قبل از آن: ۴۸۳ تست (اجرای مالک روی Windows + اجرای مستقل SQLite). این فقط سطح Unit/Feature است؛ E2E روی محیط واقعی، Security، Staging و Production هنوز ☐ هستند.

## ۱. ماتریس اصلی

| Feature | Contract | Code | Unit/Feature | E2E | Security | Staging | Production |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Main Purchase (Wallet) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Reseller Purchase + Double Debit | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Wallet (Ledger، Lock، Isolation) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| CustomerAccount (Lazy) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| **Guest Checkout (مدل جدید §3)** | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Pending → Login/Register → ادامه‌ی همان خرید | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
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
| Sale Limit / Capacity (Atomic) | ✅ | ✅ | ✅ *(SQLite + MariaDB، تک‌پردازه)* | ☐ *(Concurrency واقعی روی MySQL)* | — | ☐ | ☐ |
| Reseller Isolation (Main / A / B) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Reseller Website + Branding + Management | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Telegram Linking | ✅ | ✅ | ✅ | ☐ | ⚠️ *Self-audit فقط* | ☐ | ☐ |
| Email Verification + Gate (Purchase/Wallet Charge فقط) | ✅ | ✅ | ✅ | ☐ | ☐ | ☐ | ☐ |
| Discount (No Stacking، Eligibility از سابقه) | ✅ | ☐ *(موتور وجود ندارد، C12)* | ☐ | ☐ | — | ☐ | ☐ |
| Guest Retention (۶۰ روز) | ✅ | ✅ *(`guest:prune` روزانه)* | ✅ | — | — | ☐ | ☐ |
| بازگشت به Checkout پس از شارژ (D-3) | ✅ | ✅ *(لینک بازگشت)* | ✅ | ☐ | — | ☐ | ☐ |
| Security Headers/CSP/Rate Limit | ⚠️ *Matrix رسمی ندارد* | ✅ | ✅ | — | ☐ | ☐ | ☐ |
| Money Representation (بدون float، ارز قابل‌تنظیم) | ✅ *(M1–M8)* | ✅ | ✅ | — | — | ☐ *(Migration روی کپی DB)* | ☐ |
| Cache / RateLimiter / Queue روی Redis واقعی | ✅ | ✅ | ✅ *(فاز ۷؛ ۵ تست)* | ☐ | — | ☐ | ☐ |
| Schema Baseline (۱ Migration، MariaDB + SQLite) | ✅ | ✅ | ✅ *(`BaselineSchemaTest`)* | — | — | ☐ | ☐ |
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
| C6 | استفاده‌ی گسترده از `float` در سرویس‌های مالی | `PaymentService`, `WalletService`, … | ✅ بسته شد (فاز ۵؛ تست‌ها سبز) |

## ۴. Release Artifact

| مورد | وضعیت |
|---|---|
| VERSION = Git Tag = نام Release | ☐ هنوز Tag نخورده؛ `VERSION` فقط یک شماره است (3.3.5). قبل از Tag: `php artisan test` + commit |
| Working Tree تمیز | ☐ پس از commit فاز ۶ |
| `.env`، `webhook.json`، `melorin-backup-*.sql`، `.phpunit.result.cache` | ✅ از Artifact فاز ۶ بیرون است (همه در `.gitignore`). ⚠️ ZIPهای قبلی که این فایل‌ها را داشتند: Token/Secret ربات و رمزهای `.env` را Rotate کنید |
| `vendor/` و `node_modules/` | ✅ در Source Artifact نیست (`composer install` + `npm ci && npm run build`) |

## ۵. فاز ۳ — شکاف‌های جدید
| # | شکاف | فاز |
|---|---|---|
| C11 | Job حذف Guest بعد از ۶۰ روز نیست (`routes/console.php` فقط `provisioning:retry-failed` دارد) | ✅ بسته شد (فاز ۴) |
| C12 | موتور Discount وجود ندارد؛ فقط Contract (DS1–DS5) | Feature مستقل |
| C13 | `PaymentCallbackController` هیچ Purchase اجرا نمی‌کند (✅ مطابق D-3) ولی view `payment.callback` هیچ لینک/Redirect به Checkout ندارد و تست صریحی نیست | ✅ بسته شد (فاز ۴) |
| C8 | `.env.example` هنوز `SESSION_DRIVER=file`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | ✅ بسته شد (فاز ۴) |
| C5 | enumهای تاریخی (`payments.purpose=order`، `orders.status=pending/paid/…`) فقط مستند شدند؛ حذف نه | مستند شد |

## ۶. فاز ۴ — نتیجه
C1، C2، C3، C4، C8، C9، C10، C11، C13 در **کد و تست‌های نوشته‌شده** بسته شدند (جزئیات: `../history/PHASE-4-GUEST-CLEANUP.md`). تست‌ها بعداً اجرا و سبز شدند (بخش بالا). C6 در فاز ۵ بسته شد.

## ۷. فاز ۶ — Cleanup (نتیجه)
بدون تغییر رفتار. ۴۸۳ تست قبل و بعد سبز. جزئیات: `../history/PHASE-6-CLEANUP.md`. هیچ Migration حذف/ادغام نشد (بخش «چرا Migration ادغام نشد» در همان سند).

## ۸. تکمیل فاز ۷ (3.3.5)
Baseline Squash (۸۲ Migration → ۱؛ حذف `users.reseller_id`)، تست Redis واقعی و رفع skipهای MariaDB. جزئیات: `../history/PHASE-7-TEST-ENVIRONMENT.md`. ماتریس: ردیف‌های «Redis» و «Schema Baseline» اضافه شد؛ `MoneyMigrationTest` حذف و با `BaselineSchemaTest` جایگزین شد.
