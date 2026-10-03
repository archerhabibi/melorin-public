# Staging Runbook — Release 3.3.8 (فاز ۹)

هدف: بستن چیزهایی که با کد/تست بسته نمی‌شوند. **هیچ ردیفی با خواندن این سند ✅ نمی‌شود؛ فقط با اجرای واقعی و ثبت نتیجه در `STAGING-EVIDENCE.md`.**

همه‌ی مراحل روی **Staging با کپی دیتابیس** انجام شود، نه Production. ابزارها: `php artisan melorin:preflight`، `GET /health/ready`، `scripts/staging/smoke.sh`، `scripts/staging/backup-restore-drill.sh`.

## 0) پیش‌نیاز
- `.env` جدا (توکن ربات، `TELEGRAM_WEBHOOK_SECRET`، `DB_*`، merchant زرین‌پال با Production مشترک نباشد). `APP_ENV=staging`.
- `ZARINPAL_SANDBOX=true`، `APP_URL=https://…` (Tunnel/TLS واقعی)، `SESSION_SECURE_COOKIE=true`، `MAIL_MAILER` واقعی (SMTP/Mailpit قابل‌دسترس؛ بدون آن Verification ایمیل و در نتیجه خرید کاربر جدید قابل‌تست نیست).
- کران: `* * * * * cd /path && php artisan schedule:run` و Worker: `php artisan queue:work` (Supervisor/systemd).
- `TRUSTED_PROXIES` = IP پراکسی واقعی (Nginx/cloudflared).

## 1) Gate تنظیمات — باید سبز باشد، وگرنه ادامه ندهید
```bash
php artisan melorin:preflight --group=config --strict
```
هر `✗` یعنی تنظیم خطرناک (APP_DEBUG، Webhook Secret خالی، Sandbox اشتباه، Mailer، …). `!` را یا رفع کنید یا دلیلش را در Evidence بنویسید.

## 2) Backup → Migration → Restore Drill
```bash
mysqldump --single-transaction -u USER -p DB | gzip > backup-$(date +%F-%H%M).sql.gz
php artisan migrate --pretend && php artisan migrate --force
bash scripts/staging/backup-restore-drill.sh        # Backup واقعی را Restore و تطبیق می‌دهد + RTO را می‌سنجد
```
⚠️ Baseline (`2026_10_03_000001`) **IRREVERSIBLE** است (`down()` عمداً خطا می‌دهد). راه برگشت فقط Restore از Backup است — پس Drill بالا پیش‌شرط هر Deploy واقعی است.

## 3) Build و Gate زمان اجرا
```bash
composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan melorin:preflight --strict          # config + runtime + data
bash scripts/staging/smoke.sh https://STAGING_HOST [reseller-slug]
```
`smoke.sh` از بیرون (از مسیر واقعی Tunnel) هدرهای امنیتی، HSTS، Fail-closed وب‌هوک‌ها، Callback جعلی زرین‌پال، ایزولاسیون فروشگاه و نشتِ فایل‌های حساس را می‌سنجد.

## 4) سناریوهای دستی (هر ردیف → Evidence)
| # | سناریو | مراحل کلیدی | انتظار |
|---|---|---|---|
| P1 | شارژ کیف‌پول با Zarinpal **Sandbox** | Charge → ریدایرکت sandbox.zarinpal.com → پرداخت → بازگشت | موجودی شارژ؛ `payments.status=confirmed`؛ یک تراکنش `charge` |
| P2 | Callback تکراری | همان URL بازگشت را دوباره باز کنید | بدون شارژ دوم (`preflight --group=data` سبز) |
| P3 | پرداخت ناموفق/لغو | در Sandbox لغو کنید | `rejected`؛ موجودی بدون تغییر |
| P4 | Card-to-Card | آپلود رسید → تأیید ادمین | یک شارژ؛ آپلود مجدد فایل قبلی را پاک کند |
| G1 | Guest E2E (Main) | فرم Guest → Pending → Register → **ایمیل واقعی** → Verify → همان خرید | اکانت ساخته شود؛ `guest_checkouts` مصرف شود |
| R1 | خرید در `/store/{slug}` | شارژ مشتری + موجودی نماینده → خرید | Debit دوگانه در یک Operation؛ Main Wallet مشتری دست‌نخورده |
| T1 | Telegram Link پشت Tunnel | لینک‌کردن از Website | State یک‌بارمصرف؛ `request()->ip()` در Audit = IP واقعی کلاینت (نه 127.0.0.1) |
| T2 | وب‌هوک ربات اصلی/نماینده | `/start`، خرید، Callback دکمه | پاسخ؛ بدون Secret ← ۴۰۳ |
| A1 | ورود/خروج پنل ادمین و نماینده، `POST /sign-out` | — | بدون ۵۰۰ |

### Provisioning واقعی (پنل واقعی، نه Mock)
| # | سناریو | چگونه ایجاد کنیم | انتظار / رفتار فعلی شناخته‌شده |
|---|---|---|---|
| V1 | موفق | خرید عادی | `account_created`؛ ۱ Attempt موفق |
| V2 | Timeout | پنل را متوقف کنید (یا `iptables -A OUTPUT -d PANEL -j DROP`) | `provision_failed`، Retry خودکار (`provisioning:retry-failed`)، **بدون Debit دوباره** |
| V3 | **پاسخ گمشده** (پنل می‌سازد، پاسخ نمی‌رسد) | تأخیر > ۱۵ ثانیه (Timeout درایور) با `tc qdisc add dev eth0 root netem delay 20s` | ⚠️ **شکاف G-9-2:** Retry نام جدید می‌سازد ← اکانت یتیم روی پنل با `note=melorin-order-<id>`. اندازه‌گیری و در Evidence ثبت شود |
| V4 | درخواست تکراری | دو بار Submit همزمان (دو تب/`curl` موازی با یک توکن) | یک Debit، یک اکانت |
| V5 | **Crash وسط Provisioning** | هنگام Provisioning `kill -9` روی PHP-FPM worker/Queue worker | ⚠️ **شکاف G-9-1:** سفارش در `provisioning` می‌ماند و Retry خودکار فقط `provision_failed` را برمی‌دارد. `preflight` هشدار `orders_not_stuck` می‌دهد؛ رسیدگی: INCIDENT-RESPONSE §۵ |
| V6 | Retry بعد از Restart | Worker را Restart کنید وسط Retry | بدون Debit دوباره؛ Attempt شمارش درست |
| V7 | پنل رد کند (۴xx) | ظرفیت/اعتبار نامعتبر در پنل | `provision_failed`؛ سیاست Refund (اگر انتخاب شده) درست |

### Concurrency روی MySQL/MariaDB واقعی
```bash
export MELORIN_TEST_ALLOW_MYSQL=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=melorin_ci_test DB_USERNAME=… DB_PASSWORD=…
php artisan migrate:fresh --force && php artisan test tests/Feature/Concurrency
```
علاوه بر آن، چندپردازه‌ی واقعی (تست‌های بالا تک‌پردازه‌اند): محصول با `sale_limit=1`، دو نشست لاگین‌شده، دو درخواست موازی `POST checkout` با توکن‌های متفاوت (`xargs -P2`) ← فقط یکی موفق؛ `preflight --group=data` سبز.

## 5) Rollback Drill (Migration Baseline برگشت‌ناپذیر است)
1. **شکست HTTP عمدی:** در Staging یک خطای ۵۰۰ عمدی روی `/` بگذارید و `update-git.sh` را اجرا کنید ← باید Rollback کد انجام شود. (Readiness جدید: اگر `migrate` ناقص بماند، `melorin:preflight --group=runtime` خطا می‌دهد و Rollback فعال می‌شود.)
2. **شکست Migration عمدی:** Migration تستی که `throw` می‌کند ← Rollback + بازگشت از Backup.
3. **Restore کامل:** `bash scripts/staging/backup-restore-drill.sh --keep` و سپس Restore دستی روی دیتابیس اصلی Staging؛ بعد `melorin:preflight` و `smoke.sh`.
نتیجه و زمان‌ها در Evidence.

## 6) معیار خروج Staging
همه‌ی ردیف‌های P/G/R/T/A/V در Evidence «✅ + تاریخ + مدرک» دارند، `preflight --strict` و `smoke.sh` سبز است، Restore Drill موفق و RTO ثبت شده، و Rollback Drill سه‌گانه انجام شده. **فقط آن‌گاه** ردیف‌های ستون Staging در `canonical/VERIFICATION-MATRIX.md` ✅ می‌شوند.
