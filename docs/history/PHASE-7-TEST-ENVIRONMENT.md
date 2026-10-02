# فاز ۷ — Full Test Environment (نسخه 3.3.4)

**اجرای واقعی:** PHP 8.3.6 (mbstring/dom/xmlwriter/pdo/openssl) روی لینوکس، SQLite + MariaDB 10.11.

## نتیجه
| محیط | نتیجه |
|---|---|
| SQLite `:memory:` | ۴۸۵ تست سبز |
| MariaDB 10.11 (`migrate:fresh` + suite) | ۴۷۶ سبز، ۹ skip (`MoneyMigrationTest`، دلیل در `operations/DATABASE-TESTING.md`) |
| `migrate:fresh` روی MariaDB | ۸۲ migration، بدون خطا، ستون‌های پولی همه integer، `currency_lock=IRT:0` |
| `migrate:rollback` | از migration پول عمداً با `IRREVERSIBLE` متوقف می‌شود (طراحی‌شده، تست‌شده) |

## یافته‌ی واقعی (فقط روی MySQL دیده می‌شد)
`resellers.bot_token` ستون `varchar(255)` بود ولی با cast `encrypted` ذخیره می‌شود؛ توکن واقعی تلگرام بعد از رمزنگاری ۲۵۶ کاراکتر است ← `Data too long` (strict) یا توکن بریده/غیرقابل‌رمزگشایی (non-strict). چون پروژه Release نشده، **در Migration اصلی `create_resellers_table` به `text` اصلاح شد** (Migration جدید اضافه نشد). تست `EncryptedColumnSizeGuardTest` حالا همه‌ی مدل‌های دارای cast `encrypted` را اسکن می‌کند (Reseller, Account, ServerPanel).

## تغییرات
- `tests/TestCase.php`: مسیر اختیاری MariaDB با ۴ شرط همزمان؛ پیش‌فرض sqlite دست‌نخورده.
- Job `tests-mariadb` در CI.
- سه fixture که فقط روی sqlite معنا داشتند اصلاح شد (مبلغ کسری در ستون integer، رنگ بلندتر از ستون ۷ کاراکتری).
- هیچ رفتار محصولی غیر از نوع ستون `bot_token` تغییر نکرد.

## هنوز باز (ورودی فاز ۸/۹)
- Redis برای queue/cache در تست‌ها استفاده نمی‌شود (`sync`/`array`).
- Migration روی دیتای قدیمی (پیش از تبدیل پول) و Rollback واقعی Deploy فقط در Staging قابل سنجش است.
- Baseline Squash (`schema:dump`) هنوز انجام نشده؛ چون چند تست مستقیماً فایل Migrationهای backfill/merge/پول را اجرا می‌کنند، باید فازِ جدا و با بازنویسی آن تست‌ها باشد.
