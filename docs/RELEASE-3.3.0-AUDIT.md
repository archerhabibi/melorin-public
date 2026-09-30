# Release 3.3.0 — Audit نهایی (W3 تا W8)

تاریخ: ۲۰۲۶-۰۹-۳۰

## وضعیت واقعی

| مورد | وضعیت |
|---|---|
| `php artisan test --filter=Website` | ✅ ۹۹ تست، ۳۴۶ assertion، همه سبز |
| `php artisan test` (کل پروژه) | ❌ هنوز اجرا نشده — **قبل از Commit اجرا شود** (Core تغییر کرده: AccountService، ProvisioningService) |
| `npm run build` | ❌ اجرا نشده |
| Migration روی Staging | ❌ |
| Zarinpal Sandbox واقعی | ❌ (تست‌ها فقط Http::fake) |
| Review امنیتی مستقل (Telegram/Guest/Reseller isolation) | ❌ |
| Backup/Restore و Rollback واقعی | ❌ |
| Refund/Retry UI برای مشتری | عمداً وجود ندارد (Admin-only، طبق تصمیم W4) |

## پاک‌سازی ریپو

- `.gitignore` از قبل `.env*`، `webhook.json`، `*.sql`، `/vendor`، `/node_modules`، `/public/build` و
  `.phpunit.result.cache` را پوشش می‌دهد و هیچ‌کدام در Git ردیابی نمی‌شوند (با `git ls-files` بررسی شد).
- فایل ناخواسته‌ی `qq` (untracked) را پاک کنید.
- **Secretها**: ZIPهای اشتراک‌گذاری‌شده شامل `.env`، `webhook.json` (توکن ربات + secret_token وب‌هوک) و
  بکاپ SQL بودند. هر ZIP یا محیطی که این فایل‌ها را دیده، یعنی این secretها فاش شده‌اند →
  توکن ربات را در BotFather Revoke کنید، `TELEGRAM_WEBHOOK_SECRET` و `DB_PASSWORD` را عوض کنید،
  `APP_KEY` را فقط با آگاهی از اثرش روی session/cookie/داده‌ی رمزشده عوض کنید، وب‌هوک را دوباره ثبت کنید.
- برای ZIP تحویلی: بدون `.env`، `.git`، `vendor`، `node_modules`، `storage/logs`، `*.sql`، `webhook.json`.

## ترتیب پیشنهادی Release

```powershell
composer install --no-dev --optimize-autoloader   # فقط روی سرور؛ روی dev بدون --no-dev
php artisan test                                  # کل پروژه
Remove-Item -Recurse -Force node_modules; npm ci; npm run build
php artisan migrate --pretend                     # روی کپی دیتابیس/Staging
git add -A; git commit -m "Melorin V3.3.0 Website W0-W8 release"
git tag v3.3.0
```

## نکته‌ی باز

`CustomerAccount` از SoftDeletes استفاده می‌کند و `IdentityService::resolveCustomerAccount`
حساب soft-delete‌شده را نمی‌بیند؛ ساخت مجدد با unique index (user_id + scope) به QueryException می‌خورد.
اگر در production جایی حساب مشتری soft-delete می‌شود، تصمیم بگیرید restore شود یا ساخت مجدد مجاز باشد.
