# فاز ۹ — Staging (نسخه 3.3.7 → تکمیل در 3.3.8)

> ⚠️ **صداقت:** فاز ۹ در Roadmap یعنی «اجرای واقعی روی Staging». این کار نیازمند سرور، پنل واقعی، Sandbox زرین‌پال، ایمیل واقعی و دیتابیس واقعی است و **در این پچ انجام نشده است.** آنچه این پچ می‌دهد **ابزار، Gateها و رویه‌هایی** است که اجرای Staging را قابل‌تکرار و قابل‌اندازه‌گیری می‌کند، به‌علاوه‌ی رفع آنچه بدون Staging قابل‌رفع بود. تا پر شدن `operations/STAGING-EVIDENCE.md` هیچ ردیف Staging ✅ نمی‌شود.

## تحویل‌شده

| # | مورد | فایل |
|---|---|---|
| 1 | **Preflight** — `php artisan melorin:preflight [--group=config\|runtime\|data] [--strict] [--json] [--log]`؛ Exit Code ۰/۱؛ **فقط می‌خواند** | `app/Services/Ops/Preflight.php`، `app/Console/Commands/PreflightCommand.php` |
| 2 | **Readiness** — `GET /health/ready` (۲۰۰/۵۰۳، بدون Session/CSRF، `no-store`، Throttle ۶۰/دقیقه، بدون جزئیات). `/up` همچنان Liveness است | `app/Http/Controllers/Ops/HealthController.php`، `bootstrap/app.php` |
| 3 | **Heartbeat Scheduler** هر دقیقه + **پایش داده** هر ۱۵ دقیقه (`--group=data --log`) | `routes/console.php` |
| 4 | **Gate Readiness در `update-git.sh`**: شکست ← `exit 1` ← مسیر Rollback موجود | `update-git.sh` |
| 5 | `scripts/staging/smoke.sh` (جعبه‌سیاه) و `backup-restore-drill.sh` (Backup→Restore→تطبیق→Preflight→RTO) | `scripts/staging/` |
| 6 | مستندات: Runbook بازنویسی‌شده، Evidence، Disaster Recovery، Incident Response، Monitoring | `docs/operations/` |
| 7 | **O-6 بسته شد:** لاگ `telegram_update_resolved` نام و متن پیام را فقط با `APP_DEBUG=true` ثبت می‌کند | `WebhookController` |
| 8 | `melorin:preflight` در Allowlist قفل ارز (تا هنگام عدم‌تطابق ارز گزارش بدهد، نه Crash) | `CurrencyLock` |

### Preflight چه می‌سنجد
- **config:** `APP_KEY`، `APP_DEBUG` (Fail در Staging/Production)، `APP_URL` https، `SESSION_SECURE_COOKIE`، توکن ربات، **`TELEGRAM_WEBHOOK_SECRET`**، `TRUSTED_PROXIES=*`، Queue=sync / Cache=array، **Sandbox زرین‌پال مطابق محیط** (Staging=true، Production=false)، Mailer (Production با `log` ← Fail چون Verification ایمیل Gate خرید است)، قابل‌نوشتن‌بودن `storage`.
- **runtime:** DB، Cache (نوشتن/خواندن)، Storage، Migration عقب‌مانده، قفل ارز، Heartbeat Scheduler، Backlog صف، `failed_jobs`.
- **data:** مجموع گردش = موجودی هر Wallet؛ `balance_after` آخرین تراکنش = موجودی؛ Wallet بدون `user_id`؛ **پرداخت تأییدشده‌ی شارژ بدون تراکنش `charge`**؛ سفارش گیرکرده در `paid/provisioning` (>۱۵ دقیقه)؛ Retry سررسیدشده‌ی اجرانشده؛ `provision_failed` باز؛ Operation گیرکرده؛ نماینده‌ی فعال بدون `webhook_secret` / با webhook ناموفق.

## تأیید اجرایی (روی ماشین توسعه‌ی من، نه Staging)
- `php artisan test`: **۴۹۳ → ۵۱۷ سبز** (۲۱ تست `Operations/*` + ۳ تست `OpsReadOnlyGuardTest`)، ۵ Redis با flag.
- `smoke.sh` روی یک سرور محلی واقعی (PHP built-in + SQLite) اجرا شد: ۲۲ PASS؛ تنها FAIL مورد انتظار «Heartbeat Scheduler» (کران در آن محیط نبود) و ۱ WARN (`X-Powered-By` از خود PHP built-in). این اجرا **جایگزین** Staging واقعی نیست.
- `backup-restore-drill.sh` و `bash -n` برای سه اسکریپت: Syntax تأیید شد؛ **Drill روی MariaDB اجرا نشد** (محیط من MariaDB ندارد) ← Evidence E2 باز است.

## تکمیل 3.3.8
| مورد | وضعیت |
|---|---|
| **G-9-1** | ✅ **بسته شد در کد:** `StuckOrderWatchdog` / `provisioning:recover-stuck` (هر ۵ دقیقه، آستانه ۱۵ دقیقه، UPDATE شرطی). اکانت ثبت‌شده ← `account_created`؛ وگرنه ← `provision_failed` بدون `next_provision_retry_at`، بدون Refund/Debit، ظرفیت آزاد نمی‌شود. Audit: `provisioning.watchdog_*`. V5 در Staging همچنان باید اجرا و در Evidence ثبت شود |
| **G-9-3** | ✅ `update-git.sh` علاوه بر DB، `storage-app.tar.gz` هم می‌سازد (شکستش هشدار است، نه توقف) |
| G-9-2 | ⏳ باز — نیاز به رفتار واقعی پنل (V3) |

## شکاف‌های تازه‌کشف‌شده (از خواندن `ProvisioningService`) — اصلاح نشدند
| # | شکاف | چرا اصلاح نشد |
|---|---|---|
| **G-9-1** *(در 3.3.8 بسته شد)* | Crash/kill وسط Provisioning ← سفارش در `provisioning` می‌ماند؛ Retry خودکار فقط `provision_failed` را برمی‌دارد | تغییر State Machine مالی بدون اندازه‌گیری روی پنل واقعی خطرناک است؛ ابتدا V5 را تمرین کنید. فعلاً Preflight هشدار می‌دهد + Runbook §۵ |
| **G-9-2** | Timeout بعد از ساخته‌شدن اکانت روی پنل ← Retry نام جدید ← اکانت یتیم و ظرفیت اشغال | نیاز به رفتار واقعی پنل (آیا `note` برگردانده/قابل‌جستجوست؟) ← V3 |
| G-9-3 *(در 3.3.8 بسته شد)* | Backup `storage/app` (رسیدها/لوگو) در `update-git.sh` نبود | — |

## تصمیم‌های لازم از مالک
| # | تصمیم | پیشنهاد |
|---|---|---|
| **O-1 (High)** | Admin Authorization تخت (`canAccessPanel` همیشه `true`) — **قبل از Production** | Matrix نقش‌ها + Policy؛ فاز جدا |
| D-9-1 | RPO/RTO رسمی | Binary Log + Backup خارج سرور (دیدن DR) |
| G-9-1/2 | Watchdog برای `provisioning` گیرکرده + Adopt اکانت یتیم | بعد از V3/V5 |
| O-2 | توکن ربات در URL وب‌هوک (لاگ Nginx) | Path-token جدا (نیازمند ثبت مجدد وب‌هوک) |
| O-3 | 2FA ادمین / IP Allowlist پنل | Filament MFA |
| O-5 | مسیر `reseller.login` (Signed) بلااستفاده | حذف + حذف تست‌های مربوط |
| O-7 | `style-src 'unsafe-inline'` | Nonce |
| S1 | Independent Security Review | هنوز ☐ |

## نحوه‌ی اجرا بعد از اعمال پچ
```bash
git apply melorin-phase9.patch     # یا git am
php artisan test
php artisan melorin:preflight --strict   # روی Staging
```
سپس `docs/operations/STAGING-RUNBOOK.md` را از §۱ دنبال و `STAGING-EVIDENCE.md` را پر کنید.

## ورودی بعدی
فاز ۱۰ (Production Verification) فقط پس از: Evidence کامل، تصمیم O-1، Independent Review، و Tag (`VERSION = Git Tag = Release`).
