# Customer Wallet Contract (B3.3)

> نسخه 1.0 · Parent: Master 2.8 / Website 1.9 / Customer Dashboard 1.0 / Customer Services 1.0 · وضعیت: CANONICAL
> دامنه: صفحه‌ی کیف‌پول `GET /wallet` (`website[.store].wallet.show`) و صفحه‌ی شارژ `GET /wallet/charge`
> (`wallet.charge.show`). مسیرهای POST شارژ و رسید (`wallet.charge.store`، `wallet.receipt.*`) **تغییر نکردند**.

## W1. اصل

Website فقط Channel است. منطق در Core است:

| لایه | کلاس | نقش |
|---|---|---|
| Core | `Customer\WalletCenterService` | موجودی، خلاصه‌ی ۳۰ روز، گردش حساب فیلتر‌پذیر، شارژهای اخیر، شمارش شارژ منتظر |
| Core | `Customer\WalletCenterFilter` | پاک‌سازی و اعتبارسنجی فیلتر (جهت/نوع/تاریخ) |
| Core | `Customer\WalletChargeEntry` / `WalletOverview` | DTOهای فقط‌خواندنی |
| Website | `WebsiteWalletFacade` + `WalletController` | نگاشت هدف→URL، فقط تحویل Query به فیلتر |

- **W1.1 فقط‌خواندنی:** باز کردن کیف‌پول یا صفحه‌ی شارژ **هیچ چیزی نمی‌سازد** (نه `Wallet`، نه `CustomerAccount`، نه `Payment`).
  قبل از B3.3 همین GET با `WalletService::balanceIn` یک Wallet خالی می‌ساخت (نقض هم‌الگوی D4.1). تغییر موجودی همچنان **فقط** با `WalletService`.
- **W1.2 بدون منطق مالی جدید:** شارژ، تأیید، رد و بازگشت همان `PaymentService` است؛ B3.3 فقط نمایش و ناوبری است.
- ربات تلگرام در آینده (T3) می‌تواند از همان `WalletCenterService` بخواند.

## W2. Context-isolation و مالکیت

- Wallet = `user_id + scope_key` همین Context (Main و هر فروشگاه نماینده Wallet جدا دارند).
- شارژها = `Payment` با `user_id` + `reseller_id` همین Context + `wallet_owner_type=user` + `purpose=wallet_charge`
  (همان کلید مالکیت `Payment::findPendingForReceipt`). شارژ اعتبار خودِ نماینده (`wallet_owner_type=reseller`) هرگز نمایش داده نمی‌شود.
- **W2.1 لینک سفارش:** فقط اگر `reference` تراکنش یک `Order` باشد که به یک `CustomerAccount` همین کاربر در همین Context تعلق دارد.
  تراکنش «هزینه‌ی تأمین» Wallet نماینده به سفارش مشتریِ او اشاره می‌کند؛ برای آن لینک ساخته نمی‌شود (۴۰۴ می‌شد).

## W3. خلاصه

| کارت | تعریف |
|---|---|
| موجودی | `wallets.balance` همین Context (Wallet نبود ⇒ ۰، بدون ساخت) |
| دریافتی / پرداختی | مجموع تراکنش‌های مثبت / منفی در `SUMMARY_DAYS = 30` روز اخیر (شامل بازگشت وجه، پاداش، اصلاح ادمین) |
| شارژ در انتظار | تعداد و مجموع مبلغ شارژهای pending واقعی (W5) |

## W4. گردش حساب

- فیلتر GET: `direction` (`in`|`out`)، `type` (یکی از `WalletTransaction::typeLabels()`)، `from`/`to` (`Y-m-d` میلادی، بازه‌ی شامل‌ِ روزهای کامل).
- **W4.1 مقدار نامعتبر بی‌صدا حذف می‌شود** (نه 422): جهت/نوع ناشناخته، تاریخ غیرواقعی (`2026-02-31`)، آرایه. «از» بعد از «تا» ⇒ جابه‌جا.
- **W4.2** لینک‌های صفحه‌بندی فقط فیلتر **پاک‌سازی‌شده** را نگه می‌دارند، نه کل Query String کاربر.
- **W4.3 توضیح تراکنش** (`WalletTransaction::publicDescription()`) نمایش داده می‌شود (مثلاً «شارژ کیف پول — پرداخت #۷»، «خرید … — سفارش #۱۲»)،
  **به‌جز `referral_bonus`**: توضیح آن نام کامل کاربر دعوت‌شده را دارد (متعلق به شخص دیگر).
- ۱۵ ردیف در هر صفحه، جدیدترین اول.
- **W4.4 دو تقویم:** هر تاریخ (گردش حساب و شارژهای اخیر) هم **شمسی** (خط اصلی، `1405/07/12 14:30`) و هم **میلادی** (خط دوم) نمایش داده می‌شود؛ زیر هر فیلد تاریخِ فعال معادل شمسی‌اش هم می‌آید. فقط نمایش است: ذخیره و فیلتر میلادی می‌مانند (ورودی فیلتر `Y-m-d` میلادی از date picker مرورگر). تبدیل با `App\Support\JalaliDate` (حسابی، بدون وابستگی و بدون ext-intl).

## W5. شارژهای اخیر

وضعیت‌های واقعی `Payment` چهارتاست (`pending/confirmed/rejected/refunded`)؛ مشتری برای `pending` باید بداند منتظر چه کسی است:

| وضعیت نمایشی | شرط | رسید؟ |
|---|---|---|
| `awaiting_receipt` «در انتظار ثبت رسید» | pending + کارت‌به‌کارت + بدون رسید | **لینک «ثبت رسید»** (تنها حالتی که اکشن دارد) |
| `under_review` «در حال بررسی» | pending + کارت‌به‌کارت + رسید ثبت‌شده | — |
| `awaiting_gateway` «در انتظار نتیجه‌ی درگاه» | pending + درگاهی + کمتر از ۲۴ ساعت | — |
| `abandoned` «ناتمام» | pending + درگاهی + بیش از `GATEWAY_STALE_HOURS = 24` | — |
| `confirmed` / `rejected` / `refunded` | وضعیت نهایی | — |

- **W5.1 تعریف واحد «در انتظار»:** `awaiting_receipt + under_review + awaiting_gateway`. شارژ درگاهیِ رهاشده **شمرده نمی‌شود**.
  `CustomerDashboardService` همین شمارنده را (`pendingChargeCount`) برای اعلان `payment.pending` می‌گیرد تا داشبورد و کیف‌پول هیچ‌وقت دو عدد متفاوت نشان ندهند
  (**رفع باگ B3.1:** هر درگاه رهاشده تا ابد «شارژ در انتظار تأیید» اعلام می‌شد).
- **W5.2** این تفکیک فقط مشتق/نمایشی است؛ هیچ وضعیتی در DB تغییر نمی‌کند و `PaymentStateMachine` دست‌نخورده است.
- ۱۰ شارژ آخر، همه‌ی وضعیت‌ها.

## W6. صفحه‌ی شارژ

- موجودی فعلی (بدون نوشتن)، حداقل شارژ (`Money::minTopup()`)، مبلغ‌های پیشنهادی (`Money::topupPresets()`، فقط ≥ حداقل).
- **W6.1 پیشنهادها لینک GET هستند** (`?amount=…&product=…`)، نه JS (CSP سخت‌گیر می‌ماند) و `return_product` (D-3) را حفظ می‌کنند.
- **W6.2** `amount` در Query فقط مقدار اولیه‌ی فرم است: نامعتبر/کمتر از حداقل/آرایه ⇒ نادیده. اعتبارسنجی واقعی همچنان در `ChargeController::store` است.
- اگر فقط یک روش پرداخت فعال باشد از پیش انتخاب می‌شود. نام فیلدها (`amount`، `payment_method_id`، `return_product`) بدون تغییر.

## W7. Out of Scope

خروجی CSV/PDF گردش حساب، **ورودی** تاریخ شمسی در فیلتر (نمایش دو تقویمی انجام شده؛ ورودی همچنان date picker میلادی است)، اعلان Push/Email تأیید شارژ، شارژ خودکار/اشتراکی،
درخواست برداشت/تسویه (B5.5)، Retry/Cancel شارژ توسط مشتری، ثبت رسید مجدد پس از ثبت اولیه، نمودار موجودی.
