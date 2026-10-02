# فاز A۱ سند v2.1 — Payment Foundation (اولویت ۱/۲)

مرجع: بند ۵۱ تا ۵۵، ۱۲۴ سند و «Phase A1 — Payment Foundation» در فازبندی اجرایی سند.

## وضعیت شروع (Audit)
قبل از این فاز:
- `PaymentStateMachine` از قبل وجود داشت و گذارهای درست (`pending→confirmed/rejected`,
  `confirmed→refunded`) را تعریف می‌کرد — **اما** `PaymentService` در چهار متد
  (`finalize`، `reject`، `rejectByReseller`، `refund`) مستقیماً `$payment->update(['status'=>...])`
  می‌زد و اصلاً از این کلاس عبور نمی‌کرد. یعنی بند ۵۳ («Single Source of Truth») نقض می‌شد.
- enum دیتابیس (`pending/confirmed/rejected/refunded`) از قبل دقیقاً با گذارهای
  ماشین‌حالت یکی بود — «یکسان‌سازی» چیزی برای تغییر نداشت، فقط باید مستند می‌شد.
- Purpose (بند ۵۲) از فاز ۱۲ (پچ قبلی) قبلاً enforce شده بود (`initiate()` فقط
  `wallet_charge` می‌پذیرد).
- **باگ واقعی پیدا شد:** `reject()` و `rejectByReseller()` هیچ `lockForUpdate`/تراکنشی
  نداشتند (برخلاف `finalize()` که کامنتش صریحاً دلیل نیاز به قفل را توضیح می‌داد،
  و `refund()` که قفل داشت). یعنی اگر یک ادمین دقیقاً هم‌زمان با تاییدشدنِ یک
  پرداخت (مثلاً callback زرین‌پال) روی دکمه‌ی «رد» می‌زد، `reject()` می‌توانست
  status را بعد از این‌که `finalize()` کیف‌پول را شارژ کرده بود، بی‌صدا به
  `rejected` برگرداند — پرداخت در ظاهر رد شده ولی کیف‌پول از قبل شارژ مانده.

## تغییرات
### `PaymentStateMachine` تنها مرجع نوشتن وضعیت شد
هر چهار متد `PaymentService` حالا: `Payment::lockForUpdate()->findOrFail()` →
`$stateMachine->transition($payment, $to, $extra)`. هیچ‌جای دیگر پروژه (Filament،
هندلرهای ربات) مستقیماً وضعیت پرداخت را نمی‌نویسد — همه از طریق همین چهار متد.

### باگ Race در `reject()`/`rejectByReseller()` رفع شد
هر دو حالا داخل `DB::transaction()` با `lockForUpdate()` اجرا می‌شوند، دقیقاً مثل
`finalize()` و `refund()`. تلاش برای رد کردنِ پرداختی که هم‌زمان تایید شده، حالا
`InvalidPaymentTransitionException` می‌دهد و **هیچ نوشتنی انجام نمی‌شود** — کیف‌پول و
status هر دو دست‌نخورده می‌مانند.

### `refund()` هم اکنون Audit دارد
پیش از این `payment.approved` و `payment.rejected` در `AuditService` ثبت می‌شدند ولی
`payment.refunded` نه — با توجه به بند ۱۰۰ سند («Refund» جزو عملیات حساس)، اضافه شد.

### همه‌ی نقاط تماس با استثنای جدید سازگار شدند
`InvalidPaymentTransitionException` از `RuntimeException` می‌آید (نه `LogicException`)،
پس مسیرهایی که فقط `\LogicException` را می‌گرفتند به‌روزرسانی شدند:
- `PaymentReviewHandler` (ربات نماینده): هر دو `approve()`/`reject()`.
- دکمه‌ی «رد پرداخت» در `PaymentResource` ادمین اصلاً هیچ `try/catch` نداشت — اضافه شد.
- دکمه‌های «تایید»/«رد» در `PaymentResource` پنل نماینده هم همین‌طور — اضافه شد.
- `PaymentCallbackController` از قبل `\Throwable` را می‌گرفت، دست‌نخورده ماند.

### مستندسازی صریح (بند ۵۱، ۵۲، ۵۵)
بخش ۱۰ جدید در `docs/history/ARCHITECTURE-3.1.1-SUPERSEDED.md`: چرا سه وضعیت نظری (`created`/`processing`/
`partially_refunded`) پیاده نشده‌اند، `purpose=order` چرا کد مرده است، و مرز دقیق
Wallet Payment در برابر Direct Payment — با یادداشت صریح که «Direct Payment → Purchase»
(پرداخت مستقیم برای یک سفارش، بدون Wallet) هنوز ساخته نشده و یک قابلیت جدید و
مستقل است، نه چیزی که این فاز به‌صورت ضمنی پوشش داده باشد.

## آنچه در Scope این فاز نبود (تصمیم آگاهانه)
بند ۵۵ در سند یک Flow کامل «Direct Payment → Purchase» توصیف می‌کند (خرید مستقیم یک
سفارش از درگاه، بدون توقف در Wallet). ساختن این از پایه — Order قبل از Confirm،
اتصال `PaymentConfirmed` به Provisioning، دستکاری هر دو ربات و پنل — یک قابلیت جدید
و بزرگ است، نه رفع یک نقض موجود؛ در Audit این فاز هیچ کد نیمه‌کاره یا نادرستی برایش
پیدا نشد (چون اصلاً وجود ندارد). طبق ترتیب سند (بند ۱۳۲: «Partial Wallet + Direct
Payment» خارج از Scope) و برای این‌که این پچ در حد یک رفع‌مشکلِ قابل‌مرور بماند، این
مورد به‌عنوان یک فاز مستقلِ بعدی مستند شد، نه این‌که این‌جا نصفه‌کاره ساخته شود.

## تست‌ها
- **جدید:** `tests/Feature/Payments/PaymentStateMachineTest.php` — با
  `#[DataProvider]` هر سه گذار مجاز و هشت گذار نامعتبر (شامل تمام ترکیب‌های
  دوباره‌کاری روی وضعیت‌های پایانی) را می‌سنجد؛ برای هر گذار نامعتبر تایید می‌کند
  که **status اصلاً تغییر نکرده**.
- **اضافه‌شده به `PaymentServiceTest.php`:**
  - تایید دوباره‌ی یک پرداختِ تاییدشده → خطا + کیف‌پول فقط یک‌بار شارژ شده.
  - رد یک پرداختِ تاییدشده (شبیه‌سازی Race با نمونه‌ی درون‌حافظه‌ای کهنه) → خطا،
    status همچنان `confirmed`، کیف‌پول دست‌نخورده.
  - تایید یک پرداختِ ردشده → خطا.
  - بازگشتِ دوباره‌ی وجهِ یک پرداخت → خطا + کسرِ دوم انجام نمی‌شود.
  - webhook تکراریِ زرین‌پال (`handleCallback` دوبار با همان داده) → فقط یک‌بار
    `confirmed`، کیف‌پول فقط یک‌بار شارژ، و `verify.json` فقط یک‌بار صدا زده می‌شود
    (short-circuit موجود در `handleCallback` قبل از این فاز هم بود؛ این تست فقط
    آن رفتار را قفل می‌کند).

## اجرا
Migration ندارد.
```
php artisan test --filter=Payment
php artisan test
```
این پچ بدون اجرای PHP نوشته شده (طبق روال قبلی)؛ نتیجه‌ی واقعی `php artisan test` را
بفرستید.

## فاز بعدی پیشنهادی
طبق فازبندی سند: **Phase A2 — Financial Concurrency** (بند ۶۱ Sale Limit و بند ۶۵
Capacity، هر دو نیازمند Reservation/Row-Lock در برابر خرید هم‌زمان).
