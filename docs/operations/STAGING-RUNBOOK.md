# Staging Runbook — Release 3.3.0

هدف: بستن مواردی که با کد بسته نمی‌شوند (Migration واقعی، Sandbox پرداخت، Backup/Rollback).
همه‌ی مراحل روی **Staging با کپی دیتابیس Production** انجام شود، نه خود Production.

## 0) پیش‌نیاز
- `.env` جدا برای Staging (توکن ربات، `TELEGRAM_WEBHOOK_SECRET`، `DB_*` و merchant زرین‌پال نباید با Production مشترک باشد).
- `ZARINPAL_SANDBOX=true` (یا `sandbox: true` در settings روش پرداخت؛ مقدار روش پرداخت اولویت دارد).

## 1) Backup + Migration
```bash
mysqldump --single-transaction -u USER -p DB | gzip > backup-$(date +%F-%H%M).sql.gz
php artisan migrate:status
php artisan migrate --pretend      # بررسی SQL
php artisan migrate --force
php artisan migrate:status
```
⚠️ Migration حذف ستون‌های legacy کیف‌پول (فاز ۱۵) اگر Walletی بدون `user_id` باشد عمداً متوقف می‌شود:
`php artisan tinker --execute="echo DB::table('wallets')->whereNull('user_id')->count();"` باید `0` باشد.

## 2) Build و تست
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan route:list --name=filament.reseller.auth      # باید login و logout را نشان دهد
```
روی ماشین dev (با dev-dependencies): `php artisan test`.

## 3) سناریوهای دستی
| سناریو | انتظار |
|---|---|
| شارژ کیف‌پول با Zarinpal Sandbox | ریدایرکت به sandbox.zarinpal.com، بازگشت، موجودی شارژ، تکرار callback دوباره شارژ نکند |
| خرید مهمان (Main) | Guest → Checkout → Provisioning → اکانت |
| خرید در `/store/{slug}` | Debit دوگانه (مشتری + نماینده) |
| ورود پنل نماینده `/panel/login` و خروج | بدون ۵۰۰ |
| خروج مشتری سایت (`POST /sign-out`) | بدون ۵۰۰ |

## 4) تمرین Rollback
```bash
php artisan migrate:rollback --step=N --pretend     # N = تعداد migration این release
# یا بازگردانی کامل:
gunzip -c backup-*.sql.gz | mysql -u USER -p DB
```
و برای کد: `git reset --hard <commit قبلی>` + `composer install` (همان کاری که `update-git.sh` در خطا خودکار می‌کند).
نتیجه هر ردیف را در `docs/history/VERIFICATION-MATRIX.md` ثبت کنید.
