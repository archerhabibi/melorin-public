# فاز A3 (سند v2.1) — بخش دوم: error، started_at، finished_at (اقلام ۵ تا ۷)

مرجع: بند ۶۹ سند و ادامه‌ی
[docs/PHASE-A3-PROVISIONING-ATTEMPT-TRACKING.md](./PHASE-A3-PROVISIONING-ATTEMPT-TRACKING.md)
(اقلام ۱ تا ۴).

> **دامنه‌ی این پچ:** فقط سه قلمِ بعدیِ فازِ A3:
>
> 5. ثبتِ error
> 6. ثبتِ started_at
> 7. ثبتِ finished_at
>
> اقلامِ ۸ تا ۱۰ (تستِ Retry، تستِ Duplicate Retry، اطمینان از عدمِ Debit
> مجدد) همچنان در این پچ نیستند.

## چرا یک Migration جدا، نه ویرایشِ Migration قبلی

Migration فاز قبل (`2026_09_25_000001_create_provisioning_attempts_table.php`)
احتمالاً روی سیستمِ شما همان لحظه‌ای اجرا شده که commit را زدید. ویرایشِ
یک Migration که ممکن است از قبل اجرا شده باشد، یعنی `up()` جدید هرگز روی
آن دیتابیس دوباره اجرا نمی‌شود — دقیقاً همان اشتباهی که بندِ ۱۱۰ سند
منع کرده («Migrationها نباید کورکورانه اجرا شوند» / ترتیب باید
dependency-aware باشد). به همین دلیل یک Migration مستقلِ `ALTER TABLE`
اضافه شد: `2026_09_26_000001_add_error_and_timestamps_to_provisioning_attempts.php`.

## تصمیمِ طراحی: چرا `started_at` جدا از `created_at`

`created_at` را خودِ Eloquent موقعِ insert پر می‌کند و همیشه معادلِ لحظه‌ی
شروعِ تلاش است — امروز. اما اگر روزی ساختِ `ProvisioningAttempt` از یک صفِ
تأخیری (Job) انجام شود، `created_at` دیگر لحظه‌ی *شروعِ واقعیِ* تلاش
نیست، بلکه لحظه‌ی درج در دیتابیس است. برای این‌که این ستون از همان اول
معنایِ درست را داشته باشد (و توضیحش هم مستقل از هر Job احتمالیِ آینده
باشد)، `started_at` به‌صورت صریح در `beginAttempt()` ست می‌شود، نه
گذاشتنِ آن به `created_at`.

## تغییرات

### Migration
`database/migrations/2026_09_26_000001_add_error_and_timestamps_to_provisioning_attempts.php`
— سه ستونِ nullable روی `provisioning_attempts`: `error` (text)،
`started_at` (timestamp)، `finished_at` (timestamp).

### مدل
`ProvisioningAttempt`: سه فیلد به `$fillable` اضافه شد و
`started_at`/`finished_at` در `$casts` به `datetime` تبدیل شدند (تا
`->gte()` و امثالش روی آن‌ها در تست/کد قابل استفاده باشد).

### `ProvisioningService`

- `beginAttempt()`: حالا `'started_at' => now()` را هم موقعِ ساختِ رکورد
  می‌فرستد.
- مسیرِ موفق (داخلِ `DB::transaction`): `$attempt->update([...])` علاوه
  بر `status`، `'finished_at' => now()` را هم می‌نویسد.
- `recordFailure()`: پیام (پس از همان `mb_substr(..., 0, 1000)` که قبلاً
  فقط برایِ `orders.failure_reason` استفاده می‌شد) حالا در یک متغیرِ
  مشترک (`$truncatedReason`) نگه داشته می‌شود و هم روی سفارش، هم روی
  `$attempt->error` نوشته می‌شود؛ `finished_at` هم همان‌جا `now()` می‌شود.

  دلیلِ نگه‌داشتنِ `error` per-attempt (نه فقط `orders.failure_reason`):
  `failure_reason` با هر تلاشِ جدید بازنویسی می‌شود، پس بعد از یک
  retry موفق یا حتی یک retry ناموفقِ دیگر، دلیلِ شکستِ تلاش‌های *قبلی*
  گم می‌شود. با ثبتِ آن روی خودِ ردیفِ Attempt، تاریخچه‌ی کاملِ همه‌ی
  تلاش‌ها (بندِ ۶۹: «قابل Audit و Debug») باقی می‌ماند.

## تست

فایلِ موجودِ `tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php`
به‌روزرسانی شد (نه فایلِ جدید)، چون داشت دقیقاً همان مسیرها را پوشش
می‌داد:

- تلاشِ موفق → `error` باید `null` باشد، `started_at`/`finished_at` باید
  پر باشند و `finished_at >= started_at`.
- شکستِ پاسخِ پنل → `error` باید غیرِ `null` باشد (حاویِ واژه‌ی «پنل»)،
  `finished_at` باید پر باشد.
- نبودِ پنلِ در دسترس → همان دو Assertion.

## اجرا

```
php artisan migrate
php artisan test tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php
```

## باقی‌مانده (فاز بعدی)

اقلامِ ۸ تا ۱۰: تستِ Duplicate Retry، و اطمینانِ صریح در سطحِ کیف‌پول از
اینکه retry هیچ Debit جدیدی نمی‌سازد.
