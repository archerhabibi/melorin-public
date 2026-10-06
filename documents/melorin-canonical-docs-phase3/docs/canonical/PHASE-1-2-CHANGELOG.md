# فاز ۱ — Canonical Contract: گزارش تغییرات و تطبیق

**خروجی‌ها:** `MASTER-ARCHITECTURE-CONTRACT.md` (2.4) · `WEBSITE-ARCHITECTURE-CONTRACT.md` (1.3) · `VERIFICATION-MATRIX.md`
**دامنه‌ی فاز ۱:** فقط اسناد. هیچ خطی از کد تغییر نکرده است (اصلاح کد = فاز ۴ و ۵).

## ۱. ورودی‌ها
- گزارش ممیزی نهایی (Phase 1 = Canonical Contract؛ اولویت: Guest، Payment، Direct Payment، Verification)
- Master v2.3 (۴۹۰۱ خط)، Website v1.2 (۱۷۶۰ خط)
- ZIP پروژه (Release 3.3.0)

## ۲. یافته‌ی مهم درباره‌ی خود ZIP
گزارش ممیزی درباره‌ی ZIP قدیمی‌تری نوشته شده بود. در ZIP فعلی: `VERSION = 3.3.0`، Git HEAD = `Melorin V3.3.0`، Working Tree تمیز، و طبق `VERSION` تست‌ها (۴۱۶ تست) روی محیط واقعی سبز شده‌اند. پس بخش «Version/Git mismatch» و «تست اجرا نشد» گزارش ممیزی برای این ZIP دیگر صادق نیست. آنچه **هنوز** صادق است: ناهماهنگی نسخه‌ی *اسناد* (نام فایل v2.3 ↔ متن 2.1؛ v1.2 ↔ 1.1) و نبودن Production/Staging Verification. این عدد ۴۱۶ را من اجرا نکرده‌ام؛ از گزارش Release نقل شده است.

## ۳. تصمیم‌های اعمال‌شده (از گزارش ممیزی)

| موضوع | قبل (v2.3) | بعد (2.4) |
|---|---|---|
| Guest | سه مدل متناقض (Provisional Account / خرید بدون Account / کد: ساخت User) | یک مدل: فرم (name, phone, email*) → Pending → Login/Register → ادامه‌ی همان خرید |
| Provisional CustomerAccount | Rule 26–27 | DEPRECATED (X3) |
| ساخت خودکار User از Guest | در کد | ممنوع (G3، X4) |
| CustomerAccount | ضمن Guest ساخته می‌شد | فقط در لحظه‌ی خرید (R7؛ هم‌راستا با 3.2.17) |
| Payment Purpose | `order` + `wallet_charge` | فقط `wallet_charge` |
| Direct Payment | Confirmation → Purchase | Confirmation → Wallet Credit → Purchase از Wallet |
| Payment States | ۶ وضعیت نظری | ۴ وضعیت واقعی = enum دیتابیس؛ `created/processing/partially_refunded` خارج از Contract |
| Payment ↔ Order | وابسته | مستقل |
| Partial Wallet + Direct | خارج از Scope | بدون تغییر |
| نسخه‌بندی اسناد | 2.1 در فایل 2.3 | Master 2.4 / Website 1.3 با Parent Contract |
| Website بندهای ۹–۱۲ | دوبار با محتوای متفاوت | یک‌بار |
| رجیستر DEPRECATED | نبود | بخش ۲۰ Master |

## ۴. تناقض‌های حل‌شده (فهرست دقیق)
1. Master بند ۱۶/۱۷/۱۲۳/۱۴۶ («Guest بدون CustomerAccount خرید می‌کند») ↔ Rule 24–30 و بند ۶۲/۱۲۶/۱۴۰/۱۴۵ («Provisional Account → Pending → Login»).
2. Website بند ۷، ۶۳، ۱۰۲، ۱۰۳ ↔ بند ۸، ۹، ۶۴، ۷۰.
3. Website بندهای ۹–۱۲ تکراری با متن متفاوت.
4. Master بند ۵۱/۵۲ (Payment↔Order، purpose=order) ↔ کد (`initiate` فقط `wallet_charge`).
5. Master بند ۵۳/۱۲۴ (۶ وضعیت) ↔ `PaymentStateMachine` (۴ وضعیت).
6. Master بند ۵۵/۹۵ و Website ۲۶ (Direct → Purchase) ↔ پیاده‌سازی Wallet Charge.
7. Website بند ۸ («Email تنها فیلد الزامی») ↔ Master Rule 25 (سه فیلد) ↔ کد (phone الزامی، email اختیاری) → **D-1 باز** (پایین).

## ۴.۱ شکاف‌های باقی‌مانده‌ی Code ↔ Contract
جدول کامل در `VERIFICATION-MATRIX.md` بخش ۳ (C1–C6). خلاصه: کدِ Guest هنوز مدل قدیم است (User می‌سازد و Login می‌کند، email اختیاری، Login/Register خرید Pending را ادامه نمی‌دهد) و تست‌های Guest همان مدل را تأیید می‌کنند. این‌ها **عمداً** در فاز ۱ دست نخوردند.

## ۵. تصمیم‌های باز
| کد | سؤال |
|---|---|
| **D-1** | آیا `phone` در فرم Guest الزامی است؟ پیش‌فرضِ نوشته‌شده: الزامی (مطابق کد فعلی و Rule 25). |
| D-2 | Discount Stacking/Eligibility (از قبل باز بود). |
| D-3 | ادامه‌ی خودکار Purchase پس از شارژ Direct Payment (پیش‌فرض: خارج از Scope). |
| D-4 | Retention (Guest/Audit/Receipt). |
| D-5 | Money بدون float (فاز ۵). |

## ۶. کارهای فاز ۱ که انجام **نشد** و دلیلش
- **اسناد جانبی** (`DATA-RETENTION`, `THREAT-MODEL`, `AUTHORIZATION-MATRIX`, `RATE-LIMIT-MATRIX`, `INCIDENT-RESPONSE`, …) که گزارش پیشنهاد کرده بود: این‌ها جزو «Canonical Contract» نیستند و به فازهای ۸–۱۰ (Security/Staging) تعلق دارند.
- **جابه‌جایی پوشه‌ی docs** (`canonical/operations/history`): فقط پوشه‌ی `canonical/` ساخته شد؛ انتقال PHASE-* به `history/` را در فاز ۶ (Cleanup) پیشنهاد می‌کنم تا مسیرهای ارجاع‌شده در کد/README یک‌جا اصلاح شوند.
- **اجرای تست‌ها:** انجام نشد (PHP در محیط من نیست).

## ۷. نصب پیشنهادی
```text
docs/canonical/MASTER-ARCHITECTURE-CONTRACT.md      ← جایگزین Master v2.3
docs/canonical/WEBSITE-ARCHITECTURE-CONTRACT.md     ← جایگزین Website v1.2
docs/canonical/VERIFICATION-MATRIX.md               ← جایگزین docs/VERIFICATION-MATRIX.md
```
فایل‌های قدیمی Master/Website را حذف نکنید؛ بالای هرکدام بنویسید `Status: DEPRECATED — superseded by Master 2.4 / Website 1.3`. `ARCHITECTURE.md` ریشه و README هم باید به مسیر جدید ارجاع بدهند (فاز ۶).

## ۸. گام بعدی
فاز ۲: هماهنگ‌سازی نهایی Website Contract (اکثر آن در همین فاز انجام شد؛ باقی‌مانده: تأیید D-1 و بستن TBDهای Route/Email Verification). سپس فاز ۳ (Code/Docs Reconciliation) و فاز ۴ (Guest Cleanup: C1–C4).

---

# فاز ۲ — Website Contract: هماهنگ‌سازی با Master

**خروجی:** `WEBSITE-ARCHITECTURE-CONTRACT.md` نسخه‌ی 1.4 · Master 2.5 · Verification Matrix (به‌روز)

## تصمیم صاحب پروژه (D-1 بسته شد)
Guest Form: **Email تنها فیلد الزامی؛ name و phone اختیاری.** در Master (G2، G8، X9) و Website (بخش ۵) اعمال شد. نتیجه‌ی جانبی: `guest_name`/`guest_phone` در دیتابیس باید nullable شوند و `guest_email` NOT NULL (شکاف C2؛ اصلاح کد در فاز ۴).

## کارهای انجام‌شده
1. **تطبیق Website با Master بند‌به‌بند:** Guest (§5)، Payment (§9)، Wallet/Orders/Accounts، Refund/Retry Admin-only، هویت Lazy.
2. **بستن TBDها با واقعیت کد** (نه حدس): Route Architecture و Route Map، Store Identification، Rate Limit Matrix با مقادیر واقعی، Session/Cookie، Password Reset.
3. **جستجوی ماندگاری‌ها:** هیچ عبارت DEPRECATED (Provisional، purpose=order، Post-Purchase Resolution) در Master 2.5/Website 1.4 به‌عنوان Contract فعال باقی نمانده است (فقط در رجیستر DEPRECATED).

## یافته‌های جدید (از خواندن کد)
| # | یافته | اثر |
|---|---|---|
| ۱ | **Google Sign-In در کد وجود ندارد**، ولی Website v1.2 آن را «روش فعلی» می‌نامید | Contract اصلاح شد؛ D-6 |
| ۲ | `.env.example` → `SESSION_DRIVER=file` ولی config → `database`؛ `SESSION_SECURE_COOKIE` تعریف‌نشده | در Production باید Secure Cookie صریح فعال شود (C8) |
| ۳ | Email Verification وجود ندارد؛ چون Guest فقط با Email شروع می‌شود، هر کس می‌تواند Email دیگران را ثبت کند | D-7 پیشنهاد Verify در Register |
| ۴ | `/complete-profile` و `/guest-checkout/purchase` هر دو به مدل قدیم Guest وابسته‌اند | فاز ۴ (C9) |

## تصمیم‌های باز فعلی
| کد | سؤال | پیشنهاد |
|---|---|---|
| ~~D-6~~ | Google Sign-In | **بسته شد** — حذف از Release اول |
| ~~D-7~~ | Email Verification | **بسته شد** — بله، Register Verify می‌کند (Master G11) |
| D-8 | Gate کاربر Verify‌نشده فقط روی Purchase/Wallet Charge؟ | باز؛ پیشنهاد: بله |
| D-2 / D-3 / D-4 / D-5 | (بدون تغییر) | — |

## انجام نشد
اجرای تست/Route list (نبود PHP)؛ مقادیر Rate Limit و Session از خواندن `routes/website.php` و `config/session.php` گرفته شده‌اند، نه اجرا.

## گام بعدی
فاز ۳ — Code/Docs Reconciliation: برای هر Rule یک ردیف Document ↔ Code ↔ Test (شکاف‌های C5–C10 ورودی آن‌اند). (D-6 و D-7 بسته شدند؛ D-8 باز است.)

## به‌روزرسانی پس از تصمیم صاحب پروژه
- **D-6:** Google Sign-In از Release اول حذف شد؛ Contract و کد اکنون هم‌راستا هستند (کدی برای حذف نیست).
- **D-7:** Register باید Email را Verify کند (Master G11). در کد پیاده نیست → شکاف C10 (فاز ۴/۵).
- تصمیم‌های باز باقی‌مانده: D-2 (Discount Stacking)، D-3 (ادامه‌ی خودکار Purchase پس از شارژ)، D-4 (Retention)، D-5 (Money بدون float). هیچ‌کدام مانع فاز ۳ نیست.
