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
- Rollback واقعی Deploy و Restore از Backup فقط در Staging قابل سنجش است.
- بقیه‌ی موارد باز این فاز در بخش بعد (تکمیل فاز ۷) بسته شد.

---

# تکمیل فاز ۷ (نسخه 3.3.5)

**چرا ممکن شد:** پروژه Release نشده و دیتای واقعی ندارد؛ پس تغییر Schema و حذف Migrationهای Backfill بی‌خطر است. **اجرای واقعی:** PHP 8.3.6، MariaDB 10.11.14، Redis 7، SQLite.

## ۱) Baseline Squash (بسته شد)
| قبل | بعد |
|---|---|
| ۸۲ Migration (Backfill CustomerAccount، Merge Wallet، تبدیل اعشار→Integer، ستون‌های موقت) | ۱ فایل: `2026_10_03_000001_create_baseline_schema.php` (۴۹ جدول) |
| `users.reseller_id` (بلااستفاده؛ فقط برای Backfill) | حذف شد (ستون + FK + ایندکس) |
| تبدیل پول با گاردهای اعشار/رُند کردن | مبالغ از ابتدا `bigInteger`؛ `CurrencyLock::record()` در پایان Baseline |

- **روش:** Baseline از اسکیمای نهایی MariaDB تولید شد (introspection لاراول)، نه دستی. FKها داخل `create` هستند (SQLite افزودن FK با ALTER را نمی‌پذیرد) و جدول‌ها به ترتیب وابستگی؛ ستون‌های JSON همچنان `json`.
- **تأیید هم‌ارزی:** `mysqldump --no-data` اسکیمای قدیم و جدید، پس از مرتب‌سازی خطوط: **تنها تفاوت = حذف `users.reseller_id`** (ستون، KEY، CONSTRAINT).
- **IRREVERSIBLE:** `down()` خطا می‌دهد؛ `migrate:rollback` از Baseline عبور نمی‌کند (تست‌شده).
- **تست‌ها:** `CustomerAccountBackfillTest` (۳)، `MoneyMigrationTest` (۹) و دو تست Merge در `WalletContextStructureTest` حذف شدند چون Migration داده‌ی قدیمی دیگر وجود ندارد. تست `a_bare_user_owner_always_means_the_main_wallet` از `users.reseller_id` بی‌نیاز شد. جایگزین: `BaselineSchemaTest` (۶ تست: ستون‌های پولی integer، درصد decimal، قفل ارز، نبودِ ستون قدیمی، FK + قاعده‌ی حذف، IRREVERSIBLE).
- **دیتابیس موجود شما:** یک‌بار `php artisan migrate:fresh` (جدول `migrations` قدیمی با Baseline ناسازگار است).

## ۲) Redis واقعی (بسته شد)
`tests/Feature/Infrastructure/RedisIntegrationTest.php` — ۵ تست: Cache round-trip و `add()` اتمیک، Cache Lock، RateLimiter (hit/tooMany/clear مثل Login/Password Reset)، کش قفل ارز، و صف: Job → Redis → `queue:work` → اجرا. قفل‌ها: `MELORIN_TEST_REDIS=1`، فقط میزبان محلی، فقط DB شماره‌ی ۱۵ با prefix `melorin_ci_test_`. بدون flag skip. CI: سرویس Redis + flag در job `tests-mariadb`.

## ۳) skipهای MariaDB (بسته شد)
۹ تستِ skip‌شده همان `MoneyMigrationTest` بود که با حذف Migration تبدیل، موضوعش منتفی شد.

## نتیجه
| محیط | نتیجه |
|---|---|
| SQLite `:memory:` | ۴۷۷ سبز + ۵ Redis (با flag) = **۴۸۲** |
| MariaDB 10.11 + Redis 7 (`migrate:fresh` + suite) | **۴۸۲ سبز، ۰ skip** |
| `migrate:rollback` | با `IRREVERSIBLE` متوقف می‌شود |

## هنوز باز (فازهای بعد)
- Migration روی کپی دیتای واقعی منتفی شد (دیتا وجود ندارد)؛ **Rollback واقعی Deploy و Restore Backup** فقط در Staging.
- Concurrency واقعی چندپردازه روی MariaDB (تست‌های فعلی تک‌پردازه‌اند).
- enumهای تاریخی (`payments.purpose=order`، `orders.status`) عمداً دست نخورد (C5)؛ حالا که Baseline داریم، تنگ‌کردنشان یک تصمیم آگاهانه و کم‌ریسک است ولی هنوز انجام نشده.
