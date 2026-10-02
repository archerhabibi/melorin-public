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

## تست‌هایی که روی MySQL عمداً skip می‌شوند
`MoneyMigrationTest` (۹ تست): `up()` را روی دیتابیسِ از-قبل-migrate‌شده دوباره اجرا می‌کند و کسر را در ستون integer می‌ریزد؛ روی MySQL ستون BIGINT گرد می‌کند و DDL commit ضمنی می‌زند (قفل ارز از rollback فرار می‌کند). رفتار واقعی با `migrate:fresh` (CI) و Staging روی دیتای قدیمی سنجیده می‌شود.

## Rollback
`php artisan migrate:rollback` از migration تبدیل پول (`2026_10_01_000001_…`) عمداً با `IRREVERSIBLE` متوقف می‌شود. راه برگشت: Restore از Backup (یا `migrate:fresh` در Dev). تست `it is marked irreversible` این را قفل می‌کند.
