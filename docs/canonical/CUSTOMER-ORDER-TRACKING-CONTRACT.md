# Customer Order Tracking Contract (B4.5)

> نسخه 1.0 · Parent: Master 2.8 / Website 1.9 / Payment Experience 1.0 / Catalog 1.0 · وضعیت: CANONICAL
> دامنه: `GET /orders` (فهرست، پنل مشتری) و `GET /orders/{order}` (پیگیری تک‌سفارش) در Main و `/store/{slug}`.
> **بدون Migration، بدون Route جدید، بدون تغییر در Purchase/Provisioning/Refund.** وضعیت واقعی را همان سرویس‌ها می‌نویسند؛ این فاز فقط آن را روایت می‌کند.

## O1. Core: `Customer\OrderTrackingService` (خالص و فقط‌خواندنی)
- `track(Order): OrderTracking` — چهار مرحله «ثبت ← پرداخت ← ساخت سرویس (تمدید: اعمال تمدید) ← تحویل» با وضعیت هر مرحله (`done|current|failed|upcoming`)، `headline/message/tone`، و پرچم‌های `inProgress`، `needsSupport`، `isRenewal`، `autoRetry/nextRetryAt`. وضعیت ناشناخته ⇒ پیام خنثی (نه خطا).
- نگاشت: `pending` در انتظار پرداخت · `paid/provisioning` در جریان (inProgress) · `account_created` تحویل‌شده · `provision_failed` مرحله‌ی ۳ شکست‌خورده + needsSupport (پول کسر شده، سرویس نه) · `failed` مرحله‌ی ۲ شکست‌خورده (پرداخت نشد) · `refunded` بازگشت وجه.
- `amount(Order)`: Main ⇒ `main_price` · نماینده ⇒ `customers_price` (هرگز `reseller_price`، بند ۶).
- `group()/paginate()/counts()`: فیلتر با **گروه**، نه وضعیت خام: `active`(pending/paid/provisioning)، `delivered`، `attention`(provision_failed)، `closed`(failed/refunded). مقدار نامعتبر ⇒ همه. Scope همیشه `customer_account_id + reseller_id` همان Context است؛ شمارش با **یک کوئری**.
- Channelها (وب، در آینده ربات) منطق وضعیت را کپی نمی‌کنند.

## O2. صفحه‌ی سفارش
Breadcrumb؛ `x-ui.steps` با مرحله‌ی شکست‌خورده (`failed`: رنگ خطر + «!» + متن SR «(ناموفق)»، نه فقط رنگ)؛ هشدار وضعیت؛ کارت جزئیات (شماره‌ی پیگیری، تعرفه، **برچسب رسمی وضعیت**، نوع خرید/تمدید، مبلغ، روش پرداخت، زمان ثبت، زمان تحویل برای خریدِ تحویل‌شده).
- در جریان (`paid/provisioning`): `<meta http-equiv="refresh" content="15">` + دکمه‌ی «به‌روزرسانی وضعیت» (بدون JS؛ سازگار با CSP). `pending` و وضعیت‌های نهایی تازه‌سازی ندارند.
- تحویل‌شده: «مشاهده‌ی سرویس و اتصال» ⇒ `accounts.show`. خرید ⇒ اکانت همان سفارش؛ تمدید ⇒ اکانت تمدیدشده. **مالکیت دوباره سنجیده می‌شود**؛ اکانتِ نامالک هرگز لینک نمی‌شود.
- `provision_failed`: «ثبت تیکت برای این سفارش» + یادآوری شماره‌ی پیگیری؛ «سامانه خودکار دوباره تلاش می‌کند» فقط اگر `next_provision_retry_at` ثبت شده (و فقط در همین وضعیت). **`failure_reason` و هر جزئیات داخلی/پنل هرگز نمایش داده نمی‌شود.**
- مالکیت/Context بدون تغییر: سفارش دیگران یا Store دیگر ⇒ 404. لینک‌ها در `/store/{slug}` می‌مانند.

## O3. فهرست
چیپ‌های GET (`?group=`) با شمار هر گروه؛ هر ردیف: تعرفه، شماره، تاریخ شمسی، مبلغ، نشان «تمدید»، نشان وضعیت از Core، و پیام کوتاه برای نیازمند رسیدگی. فیلتر در صفحه‌بندی حفظ می‌شود؛ حالت خالیِ «هنوز سفارشی نیست» و «با این وضعیت سفارشی نیست» جدا هستند. تعداد کوئری به تعداد ردیف‌ها وابسته نیست.

## O4. Out of scope
Push/اعلان تغییر وضعیت، Retry/Refund توسط مشتری (Admin-only)، نمایش Config/QR در صفحه‌ی سفارش (بخش Accounts)، فاکتور/PDF، لینک مستقیم تیکت با پیش‌پر شدن سفارش، پیگیری سفارش در ربات، تاریخچه‌ی تلاش‌های Provisioning برای مشتری.
