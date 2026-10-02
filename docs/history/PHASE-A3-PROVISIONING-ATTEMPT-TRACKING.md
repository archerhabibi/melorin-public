# فاز A3 (سند v2.1) — Provisioning Attempt Tracking (اقلام ۱ تا ۴)

> **به‌روزرسانی:** اقلامِ ۵ تا ۷ (error/started_at/finished_at) در پچِ
> بعدی اضافه شدند — ر.ک.
> [docs/history/PHASE-A3-PART2-ERROR-AND-TIMESTAMPS.md](./PHASE-A3-PART2-ERROR-AND-TIMESTAMPS.md).
> اقلامِ ۸ تا ۱۰ (تستِ Retry/Duplicate Retry/عدمِ Debit مجدد) هم در
> [docs/history/PHASE-A3-PART3-RETRY-TESTS.md](./PHASE-A3-PART3-RETRY-TESTS.md)
> اضافه شدند — با آن پچ، فازِ A3 کامل می‌شود.

مرجع: بند ۶۹ سند («Provisioning Attempt Tracking») و «Phase A3 — Provisioning
Tracking» در فازبندی اجرایی.

> **دامنه‌ی این پچ:** فقط ۴ قلمِ اول از ۱۰ قلمِ فازِ A3، طبق درخواستِ فعلی:
>
> 1. ایجادِ ProvisioningAttempt
> 2. ثبتِ operation_id
> 3. ثبتِ attempt_number
> 4. ثبتِ status
>
> اقلامِ ۵ تا ۱۰ (error، started_at، finished_at، تستِ Retry، تستِ Duplicate
> Retry، اطمینان از عدمِ Debit مجدد) عمداً در این پچ نیستند.

## وضعیت شروع

تا امروز تنها ردِ یک تلاشِ Provisioning، شمارنده‌ی ساده‌ی
`orders.provision_attempts` بود (یک عدد، روی خودِ سفارش). از روی آن
نمی‌شد فهمید:

- هر تلاش دقیقاً به کدام درخواستِ Idempotent (`operations` — مثلاً کدام
  کلیدِ خرید یا کدام retry دستیِ ادمین) متعلق بوده،
- نتیجه‌ی *هر* تلاش به‌طور جداگانه چه بوده (فقط وضعیتِ *نهاییِ* سفارش
  دیده می‌شد، نه تاریخچه‌ی تلاش‌به‌تلاش).

## تصمیم طراحی: `order_id` روی جدول، هرچند در سند نیامده

بندِ ۶۹ سند فیلدهای `ProvisioningAttempt` را این‌طور فهرست می‌کند:
`operation_id، attempt_number، status، error، started_at، finished_at`. بدون
یک ستونِ `order_id`، عملاً نمی‌شود «تلاش‌های یک سفارش» را پیدا کرد — پس این
ستون اضافه شد؛ `operation_id` عمداً `nullable` است، چون تلاش‌های
`claimForRetry` (کلیکِ دستیِ ادمین از پنلِ سفارش‌ها) همیشه یک `Operation`
ندارند.

## تغییرات

### Migration
`database/migrations/2026_09_25_000001_create_provisioning_attempts_table.php`
— جدولِ `provisioning_attempts`: `order_id` (FK، cascade)، `operation_id`
(FK، nullable، nullOnDelete)، `attempt_number` (unsigned int)، `status`
(string) و timestampsِ استاندارد. ستون‌های `error`/`started_at`/`finished_at`
عمداً حذف شده‌اند تا در یک Migration مستقلِ بعدی (همان الگویی که فازهای
قبلی — مثلاً افزودنِ تدریجیِ ستون به `orders` در چند فازِ جدا — استفاده
کرده‌اند) اضافه شوند.

### مدل
`App\Models\ProvisioningAttempt` (+ `database/factories/ProvisioningAttemptFactory.php`
برای تست) با سه ثابتِ وضعیت: `STATUS_STARTED`، `STATUS_SUCCEEDED`،
`STATUS_FAILED`. رابطه‌های `order()` و `operation()`.

`Order::provisioningAttempts()` — رابطه‌ی `hasMany` جدید.

### `ProvisioningService`

هر جایی که `provision()` واقعاً یک تلاش را شروع می‌کند — یعنی همان دو نقطه‌ای
که قبلاً `provision_attempts` را افزایش می‌دادند («بدون پنلِ در دسترس» و
مسیرِ اصلی بعد از رزروِ موفقِ ظرفیت — بند ۶۵/فاز A2) — یک متدِ جدید،
`beginAttempt()`، بلافاصله یک ردیفِ `ProvisioningAttempt` با
`status = started` می‌سازد:

```php
protected function beginAttempt(Order $order, ?Operation $operation): ProvisioningAttempt
{
    return ProvisioningAttempt::create([
        'order_id' => $order->id,
        'operation_id' => $operation?->id,
        'attempt_number' => (int) $order->provision_attempts,
        'status' => ProvisioningAttempt::STATUS_STARTED,
    ]);
}
```

`attempt_number` از خودِ `$order->provision_attempts` خوانده می‌شود، دقیقاً
همان لحظه‌ای که این شمارنده تازه افزایش یافته — یعنی رکورد تلاش و شمارنده‌ی
سفارش هرگز از هم عقب نمی‌افتند.

نتیجه‌ی تلاش:
- **موفق:** داخل همان `DB::transaction` که `Account` ساخته می‌شود،
  `$attempt->update(['status' => 'succeeded'])`.
- **ناموفق:** هر چهار نقطه‌ی شکستِ موجود (بدون ظرفیت، استثنای درایور،
  پاسخِ ناموفقِ پنل، شکستِ ثبت در دیتابیس) حالا `$attempt` را هم به
  `recordFailure()` پاس می‌دهند.

`recordFailure()` امضای جدید گرفت:

```php
public function recordFailure(
    Order $order,
    string $reason,
    ?Operation $operation = null,
    ?ProvisioningAttempt $attempt = null,
): void
```

اگر `$attempt` داده نشود — تنها مسیرِ باقی‌مانده، کاتچِ استثنای غیرمنتظره در
`PurchaseService::retryProvisioning()` است، که از قبل بدون هیچ Attempt به
`recordFailure` زنگ می‌زد — خودش آخرین تلاشِ `started` همان سفارش را پیدا و
`failed` می‌کند:

```php
$attempt ??= $order->provisioningAttempts()
    ->where('status', ProvisioningAttempt::STATUS_STARTED)
    ->latest('id')
    ->first();
```

این تضمین می‌کند هیچ تلاشی برای همیشه در `started` یتیم نماند، حتی وقتی
تماس‌گیرنده صراحتاً Attempt را در دست ندارد.

### اثرِ جانبیِ کوچکِ رفع‌شده

شاخه‌ی «بدون پنلِ در دسترس» تا امروز `$operation` را به `recordFailure`
پاس نمی‌داد — برخلافِ سه شاخه‌ی دیگر که همیشه پاس می‌دادند (یعنی
`Operation::markFailed()` برای این یک حالت هرگز صدا زده نمی‌شد). با اضافه‌شدنِ
پارامترِ `$attempt` به همان فراخوانی، این ناهماهنگی هم هم‌راستا شد.

## تست

`tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php`:

- تلاشِ موفق → یک `ProvisioningAttempt` با `status=succeeded`،
  `attempt_number=1` و `operation_id` برابرِ همان `Operation`ی که
  `PurchaseService::purchase()` با `idempotencyKey` ساخته.
- شکستِ پاسخِ پنل → یک تلاشِ `failed`.
- نبودِ هیچ پنلِ متصل به دسته‌بندی → همچنان یک تلاشِ `failed` ثبت می‌شود
  (نه صفر تلاش).
- `retryProvisioning()` بعد از یک شکست → تلاشِ دوم با `attempt_number=2` و
  `status=succeeded`، بدون این‌که تلاشِ اول تغییر کند.

## اجرا

```
php artisan migrate
php artisan test tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php
```

این پچ بدون اجرای واقعیِ PHP نوشته شده؛ نتیجه‌ی واقعیِ `php artisan test`
(کل Suite) را برای تأیید ارسال کنید.

## باقی‌مانده (فاز بعدی)

- ستون‌های `error`، `started_at`، `finished_at` روی `provisioning_attempts`
  (اقلامِ ۵ تا ۷).
- تستِ صریحِ Duplicate Retry (قلمِ ۹).
- تستِ صریحِ «retry هیچ Debit جدیدی نمی‌سازد» در سطحِ کیف‌پول، نه فقط در
  سطحِ Provisioning (قلمِ ۱۰؛ رفتار از قبل در `PurchaseService::retryProvisioning`
  درست است — این پچ فقط تستِ اختصاصیِ آن را اضافه نکرده).
