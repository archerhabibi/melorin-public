# فاز ۳ — Code/Docs Reconciliation

**مبنا:** Master 2.7 · Website 1.6 · کد Release 3.3.0 (خواندن ایستا؛ **هیچ تستی اجرا نشد**، PHP در محیط من نیست).
**قاعده:** ✅ = با grep/خواندن کد تأیید شد · ⚠️ = مغایر/ناقص · ☐ = نیست. «Test» یعنی فایل تست **وجود دارد**، نه اینکه اجرا شده.

## ۱. تصمیم‌های اعمال‌شده در اسناد
D-8 (Gate فقط Purchase/Wallet Charge، enforcement در Core) · D-2 (No Stacking، Eligibility از سابقه‌ی Core، واحد = CustomerAccount) · D-3 (بازگشت به Checkout، خرید خودکار ممنوع) · D-4 (Guest ۶۰ روز؛ بقیه طبق `DATA-RETENTION.md`) · D-5 (Integer Minor Unit، بخش ۴.۱). D-5a: واحد = تومان.

## ۲. ماتریس Rule ↔ Code ↔ Test

| Rule | Code | Test (فایل موجود) | نتیجه |
|---|---|---|---|
| R1–R6 هویت/Membership | ✅ CustomerAccount، `users.reseller_id` بلااستفاده در جدول | MembershipGuardTest, FinalModelSpecTest | ✅ (بدهی: ستون `users.reseller_id`) |
| R7 CustomerAccount Lazy | ✅ `findCustomerAccount` در Middleware، ساخت در Checkout | LazyCustomerAccountCreationTest | ✅ |
| G1–G8 Guest | ⚠️ مدل قدیم (C1–C4, C9) | GuestPurchaseFlowTest و ۲ تست دیگر مدل قدیم را تأیید می‌کنند | ⚠️ فاز ۴ |
| G9 Retention ۶۰ روز | ☐ فقط `retry-failed` زمان‌بندی دارد | — | ☐ C11 |
| G10 Telegram Linking | ✅ | TelegramLinkingTest | ✅ (Review مستقل لازم) |
| G11 Email Verify + Gate D-8 | ☐ بدون `MustVerifyEmail`/Route/Gate | — | ☐ C10 |
| §4 سه قیمت/نام‌های Legacy | ✅ | PricingNamingAndReportsTest | ✅ |
| §4.1 Money (M1–M5) | ⚠️ ۸۱ `(float)`: Services/Core 24، Filament 25، Channels 21، Resellers 4، Model 1 | — (Guard M5 نیست) | ⚠️ فاز ۵ |
| W1–W9 Wallet | ✅ | WalletServiceIsolationTest, WalletServiceTest | ✅ |
| §6 Purchase/Double Debit/Sale Limit | ✅ | SaleLimitConcurrencyTest، تست‌های Purchase | ✅ (SQLite تک‌پردازه؛ MySQL واقعی ☐) |
| §7.1 Purpose فقط `wallet_charge` | ✅ `PaymentService::initiate` رد می‌کند | PaymentServiceTest | ✅ (enum DB تاریخی = C5) |
| §7.2 D-3 | ⚠️ callback Purchase نمی‌زند ✅؛ view لینک Checkout ندارد | — | ⚠️ C13 |
| §7.3 State Machine | ✅ | PaymentStateMachineTest | ✅ |
| §7.4 رسید | ✅ | ReceiptSecurityTest | ✅ |
| §8 Provisioning/Attempt/Retry | ✅ | ProvisioningAttemptTrackingTest, FailurePolicyTest | ✅ (پنل واقعی ☐) |
| §16 Discount | ☐ موتور نیست؛ `isFirstPurchase` فقط Referral | — | ☐ C12 (Feature مستقل) |
| §17 Migration Irreversible | ⚠️ Merge Wallet و حذف ستون Legacy `down()` no-op؛ علامت `IRREVERSIBLE` در Deploy Contract نیست | — | ⚠️ مستندسازی |
| Session/Cookie (C8) | ⚠️ `.env.example` = `file` ↔ config = `database`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | — | ⚠️ اصلاح ۲ خط |

## ۳. یافته‌های مهم فاز ۳
1. **Discount در کد وجود ندارد.** D-2 الان فقط Contract است؛ نباید در Matrix «Implemented» دیده شود.
2. **صفحه‌ی callback درگاه لینک بازگشت ندارد** (D-3 در سند هست، در UI نیست).
3. **Filament (25 مورد) و Channels (21 مورد) هم `float` دارند**، نه فقط Core؛ دامنه‌ی فاز ۵ بزرگ‌تر از «سرویس‌های مالی» است.
4. Email Verify Gate باید در Core باشد؛ Middleware Website به‌تنهایی Bot/Filament را نمی‌پوشاند.

## ۴. Retention غیر-Guest (تأییدشده)
جدول `DATA-RETENTION.md`. اعداد ۱۰ سال / ۲۴ ماه / ۱۲ ماه **پیشنهاد من** هستند و من وکیل/حسابدار نیستم؛ الزام قانونی نگهداری اسناد مالی را با متخصص تأیید کنید.

## ۵. ورودی فاز ۴
C1–C4, C9 (Guest) · C10 (Verify + Gate) · C11 (Job حذف) · C13 (لینک Checkout) · C8 (`.env.example`) · و در فاز ۵: C6 با محدوده‌ی جدید.

## ۶. انجام نشد
اجرای تست/`route:list`/`migrate` · بررسی همه‌ی ۷۹ Migration برای `down()` (فقط نمونه‌ی ابتدایی دیده شد) · مقایسه‌ی خط‌به‌خط ۶۸ فایل تست با Ruleها.
