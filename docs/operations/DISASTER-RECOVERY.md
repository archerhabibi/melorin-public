# Disaster Recovery

> **وضعیت:** رویه‌ها و ابزار آماده‌اند؛ **هیچ‌کدام هنوز روی Staging اجرا نشده‌اند** (`STAGING-EVIDENCE.md` E2/B3). اعداد RPO/RTO «هدف پیشنهادی» هستند و تصمیم مالک لازم دارند (D-9-1).

## چه چیزی باید Backup شود
| داده | محل | در `update-git.sh`؟ | یادداشت |
|---|---|:-:|---|
| دیتابیس (کیف‌پول/Ledger/سفارش‌ها) | MySQL/MariaDB | ✅ (قبل از هر Update) | `mysqldump --single-transaction` |
| رسیدهای کارت‌به‌کارت، لوگوی نمایندگان | `storage/app` (دیسک private) | ⚠️ | هر اجرای `update-git.sh` فایل `storage-app.tar.gz` می‌سازد (فقط هنگام Deploy). برای Backup دوره‌ای، `rsync/tar` روزانه‌ی جدا لازم است |
| `.env` | سرور | ❌ | فقط رمزگذاری‌شده و خارج از ریپو/ZIP. `APP_KEY` حیاتی است (بخش پایین) |
| پیکربندی Nginx/Supervisor/cron | سرور | ❌ | مستند یا IaC |

### ⚠️ APP_KEY
ستون‌های `resellers.bot_token`، `resellers.webhook_secret`، `server_panels.credentials`، `accounts.config_data` با `APP_KEY` رمز شده‌اند. بدون همان کلید، Restore دیتابیس این مقادیر را **ناخواناپذیر** می‌کند. کلید را جدا از Backup دیتابیس، امن نگه دارید. تغییر/Rotate `APP_KEY` بدون Re-encrypt همین اثر را دارد.

## RPO / RTO — هدف پیشنهادی (نیازمند تصمیم)
| | Backup روزانه (وضعیت فعلی) | + Binary Log (PITR) |
|---|---|---|
| RPO | تا ۲۴ ساعت — **تا ۲۴ ساعت گردش مالی (شارژ/خرید) ممکن است گم شود** | ≤ ۵–۱۵ دقیقه |
| RTO | با Drill اندازه‌گیری می‌شود (`backup-restore-drill.sh` زمان واقعی را چاپ می‌کند) | + زمان Replay |

برای سیستمی که پول واقعی نگه می‌دارد، توصیه: Binary Log + Backup خارج از سرور. تا قبل از تصمیم، عدد رسمی ثبت نشود.

## رویه‌ی Restore
1. `php artisan down --secret=…`
2. Restore: `gunzip -c backup.sql.gz | mysql -u USER -p DB` (یا روی دیتابیس تازه و سوییچ `DB_DATABASE`).
3. `storage/app` را از Backup فایل برگردانید؛ `.env`/`APP_KEY` اصلی را بگذارید.
4. `php artisan migrate:status` (انتظار: همه Ran) — **`migrate:rollback` از Baseline عبور نمی‌کند.**
5. `php artisan melorin:preflight --strict` ← `ledger_matches_balance`، `confirmed_payments_credited` باید سبز باشند.
6. فقط پس از سبز بودن Preflight: `php artisan up` و `scripts/staging/smoke.sh`.
7. تفاوت زمانی Backup تا Incident را از روی Gateway (زرین‌پال) و پنل‌ها تطبیق دهید (پرداخت/خرید بعد از Backup در DB نیست ولی واقعاً رخ داده).

## Drill
حداقل ماهی یک‌بار و قبل از هر Release: `bash scripts/staging/backup-restore-drill.sh` (دیتابیس موقت `*_restore_drill`؛ مبدأ را نمی‌نویسد).
