# فاز A3 (سند v2.1) — بخش سوم: تست Retry، Duplicate Retry، عدم Debit مجدد (اقلام ۸ تا ۱۰)

مرجع: بند ۶۹ سند و ادامه‌ی
[docs/PHASE-A3-PROVISIONING-ATTEMPT-TRACKING.md](./PHASE-A3-PROVISIONING-ATTEMPT-TRACKING.md)
(اقلام ۱ تا ۴) و
[docs/PHASE-A3-PART2-ERROR-AND-TIMESTAMPS.md](./PHASE-A3-PART2-ERROR-AND-TIMESTAMPS.md)
(اقلام ۵ تا ۷).

> **دامنه‌ی این پچ:** سه قلمِ آخرِ فازِ A3:
>
> 8. تستِ Retry
> 9. تستِ Duplicate Retry
> 10. اطمینان از عدمِ Debit مجدد
>
> با این پچ، فازِ A3 (بندِ ۶۹) **کامل** می‌شود.

## نکته‌ی مهم: این سه مورد از قبل *به‌صورت رفتار* پیاده‌سازی شده بودند

Retry بدون Debit مجدد (بندِ ۷۵) و جلوگیریِ Duplicate Retry (بندِ ۲۵) از
همان فازِ ۱۱ (`ProvisioningFailureHandler` و
`ProvisioningService::claimForRetry`) در کدِ واقعی وجود داشتند — این پچ
چیزِ جدیدی در منطقِ کسب‌وکار اضافه نمی‌کند، فقط سه Test برای آن‌ها اضافه
می‌کند که مستقیماً به رکوردهایِ `ProvisioningAttempt` (موضوعِ خودِ فازِ A3)
هم نگاه می‌کنند — چیزی که `tests/Feature/Provisioning/FailurePolicyTest.php`
(که پیش‌تر و مستقل از فازِ A3 نوشته شده) پوشش نمی‌داد، چون آن فایل قبل از
وجودِ جدولِ `provisioning_attempts` نوشته شده بود.

## تست‌های اضافه‌شده

همه در `tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php`
(فایلِ موجود؛ فایلِ جدید نیست):

### قلمِ ۸ و ۱۰ — `retrying_a_failed_order_succeeds_and_records_the_next_attempt_without_a_new_debit`

سناریو: خرید با شکستِ پنل → Debit یک‌بار (`main_price`) قطعی می‌شود
→ `retryProvisioning()` با پنلِ سالم دوباره صدا زده می‌شود.

بررسی می‌کند:
- موجودیِ کیف‌پول بلافاصله بعدِ شکستِ Purchase، دقیقاً `500000 − 100000 = 400000`
  است (بندِ ۹۶: Payment/Purchase موفق ≠ Provisioning موفق — Debit در
  مرحله‌ی مالی قطعی شده، صرف‌نظر از نتیجه‌ی Provisioning).
- بعد از `retryProvisioning()` موفق، دو ردیفِ `ProvisioningAttempt`
  هست: شماره‌ی ۱ (`failed`) و شماره‌ی ۲ (`succeeded`).
- موجودیِ کیف‌پول بعد از retry موفق، **دقیقاً همان** مقدارِ قبل از retry
  است — یعنی هیچ Debit دومی اتفاق نیفتاده.

### قلمِ ۹ — `a_concurrent_duplicate_retry_is_rejected_without_a_new_attempt_or_debit`

سناریو: به‌جای شبیه‌سازیِ واقعیِ دو Thread هم‌زمان (که در PHPUnit مستقیم
ممکن نیست)، دقیقاً همان شرطی تست می‌شود که `claimForRetry` را atomic
می‌کند: فرآیندِ اول با فراخوانیِ مستقیمِ
`ProvisioningService::claimForRetry($order)`، سفارش را claim می‌کند
(`provision_failed → provisioning`) — دقیقاً همان کاری که
`retryProvisioning()` قبل از صدازدنِ `provision()` انجام می‌دهد. سپس
یک فرآیندِ «دوم» (شبیه‌سازیِ retry تکراری/هم‌زمان) با
`$this->purchase->retryProvisioning($order->fresh())` صدا زده می‌شود.

بررسی می‌کند:
- فراخوانیِ دوم `PurchaseNotAllowedException` می‌دهد (چون سفارش دیگر
  `provision_failed` نیست).
- هیچ ردیفِ `ProvisioningAttempt` جدیدی ساخته نشده — چون `claimForRetry`
  دومی قبل از رسیدن به `beginAttempt()` رد شده.
- موجودیِ کیف‌پول دست‌نخورده مانده.

## اجرا

```
php artisan test tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php
```

با این پچ، هر ۱۰ قلمِ فازِ A3 (بندِ ۶۹) تکمیل شده است.
