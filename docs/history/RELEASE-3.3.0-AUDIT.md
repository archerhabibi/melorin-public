# Release 3.3.0 — Audit نهایی (W3 تا W8)

تاریخ: ۲۰۲۶-۰۹-۳۰

## وضعیت واقعی

| مورد | وضعیت |
|---|---|
| `php artisan test --filter=Website` | ✅ ۹۹ تست، ۳۴۶ assertion، همه سبز |
| `php artisan test` (کل پروژه) | ✅ ۴۱۶ تست، ۱۳۹۸ assertion، همه سبز (اجرای واقعی روی ماشین صاحب پروژه) |
| `VERSION` | ✅ 3.3.0 |
| `npm run build` | ⏳ روی ماشین شما؛ `update-git.sh` از این نسخه خودش build می‌زند، `install.sh` از قبل می‌زد |
| Migration روی Staging | ⏳ نیاز به Staging — `docs/operations/STAGING-RUNBOOK.md` |
| Zarinpal Sandbox واقعی | ⏳ نیاز به merchant sandbox — `ZARINPAL_SANDBOX=true` حالا واقعاً اثر دارد (قبلاً بی‌اثر بود) |
| Review امنیتی مستقل (Telegram/Guest/Reseller isolation) | ⏳ نیاز به شخص مستقل؛ self-audit در PHASE-W6-PART5 |
| Backup/Restore و Rollback واقعی | ⏳ نیاز به Staging — `docs/operations/STAGING-RUNBOOK.md` (update-git.sh خودکار rollback دارد، هنوز تمرین نشده) |
| Refund/Retry UI برای مشتری | عمداً وجود ندارد (Admin-only، طبق تصمیم W4) |

## رفع‌شده در این Audit (علاوه بر فهرست VERSION)

- **تداخل مسیر Filament/Website**: `/login` و `POST /logout` پنل نماینده (path('')) با مسیرهای سایت یکی بودند و
  نام route از جدول می‌افتاد؛ → `/panel/login` و `/sign-out`. نکته: `/` تداخل ندارد، چون در پنل‌های tenant
  دار مسیر خانه `/{tenant}` است نه `/`.
- `ZARINPAL_SANDBOX` بی‌اثر بود؛ حالا پیش‌فرضِ `settings.sandbox` است.
- `update-git.sh` مرحله‌ی `npm run build` نداشت.

## پاک‌سازی ریپو

- `.gitignore` از قبل `.env*`، `webhook.json`، `*.sql`، `/vendor`، `/node_modules`، `/public/build` و
  `.phpunit.result.cache` را پوشش می‌دهد و هیچ‌کدام در Git ردیابی نمی‌شوند (با `git ls-files` بررسی شد).
- فایل ناخواسته‌ی `qq` (untracked) را پاک کنید: `Remove-Item qq`.
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
