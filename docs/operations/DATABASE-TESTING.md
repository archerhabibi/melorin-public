# تست روی دیتابیس (SQLite پیش‌فرض، MariaDB/MySQL اختیاری)

## پیش‌فرض
`php artisan test` روی `sqlite :memory:` اجرا می‌شود. `tests/TestCase.php` هر اتصال دیگری را قبل از هر migrate/RefreshDatabase رد می‌کند (قفل ایمنی برای اینکه تست هرگز به دیتابیس واقعی نرسد).

## مسیر اختیاری: MariaDB/MySQL
برای چیزی که sqlite نمی‌سنجد (طول ستون، strict mode، `lockForUpdate`، DDL واقعی):

```bash
mysql -e "CREATE DATABASE melorin_ci_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
export MELORIN_TEST_ALLOW_MYSQL=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 \
       DB_DATABASE=melorin_ci_test DB_USERNAME=... DB_PASSWORD=...
php artisan migrate:fresh --force
php artisan test
```

Guard فقط وقتی باز می‌شود که **هر چهار شرط** برقرار باشد: `MELORIN_TEST_ALLOW_MYSQL=1` (فقط در shell/CI، نه `.env`/`phpunit.xml`) · اتصال `mysql|mariadb` · نام دیتابیس دقیقاً `melorin_ci_test` · میزبان `127.0.0.1`/`localhost`/`::1`.
⚠️ RefreshDatabase روی این دیتابیس جدول‌ها را می‌سازد/پاک می‌کند؛ هرگز دیتابیس دیگری را به این نام نده.

CI همین کار را در job `tests-mariadb` (`.github/workflows/tests.yml`) انجام می‌دهد.

## مسیر اختیاری: Redis واقعی (Cache / RateLimiter / Queue)
پیش‌فرض تست‌ها `array`/`sync` است. `tests/Feature/Infrastructure/RedisIntegrationTest.php` (۵ تست: Cache، Lock اتمیک، RateLimiter، کش قفل ارز، صف + Worker) فقط وقتی اجرا می‌شود که:

```bash
export MELORIN_TEST_REDIS=1      # فقط در shell/CI، نه .env/phpunit.xml
# Redis باید روی 127.0.0.1 باشد (phpredis یا predis)
php artisan test --filter=RedisIntegrationTest
```

همیشه روی **دیتابیس شماره‌ی ۱۵** Redis با prefix `melorin_ci_test_` کار می‌کند و فقط همان را `flushdb` می‌کند؛ میزبان غیرمحلی رد می‌شود. بدون flag همه skip می‌شوند. CI (job `tests-mariadb`) سرویس Redis دارد و flag را فعال می‌کند.

## Baseline و Rollback
۸۲ Migration پیشین در **یک Baseline** ادغام شد (`2026_10_03_000001_create_baseline_schema.php`)؛ تاریخچه‌ی قبلی در Git است. `down()` عمداً با `IRREVERSIBLE` خطا می‌دهد: `php artisan migrate:rollback` از Baseline عبور نمی‌کند. راه برگشت: Restore از Backup (یا `migrate:fresh` در Dev). هر تغییر بعدی اسکیما = Migration جدید، نه ویرایش Baseline.

روی دیتابیسی که با Migrationهای قدیمی ساخته شده (جدول `migrations` با batchهای قدیمی) یک‌بار `php artisan migrate:fresh` لازم است (پروژه Release نشده؛ دیتای واقعی وجود ندارد).

اسکیمای Baseline روی MariaDB 10.11 با اسکیمای حاصل از ۸۲ Migration قدیمی مقایسه شد (`mysqldump --no-data`، مرتب‌شده): تنها تفاوت، حذف ستون `users.reseller_id` (و FK/ایندکسش) است.

تست‌های MariaDB دیگر skip ندارند؛ `MoneyMigrationTest` و تست‌های Backfill/Merge چون Migration داده‌ی قدیمی ندارند حذف شدند و `BaselineSchemaTest` (۶ تست) جایگزینشان شد.
