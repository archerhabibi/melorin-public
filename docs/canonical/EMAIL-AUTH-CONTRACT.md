# Melorin — Email Authentication Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 1.0 |
| **وضعیت** | CANONICAL (Contract مستقل) |
| **Parent** | Master Architecture Contract 2.8 · Website Architecture Contract 1.9 · `GOOGLE-SIGNIN-CONTRACT.md` 1.2 |
| **فاز** | B2.2 (Release 3.3.10) |

## ۱. دامنه
سخت‌کردن مسیرهای احراز هویت با Email (Register، Login، Forgot/Reset Password، Email Verification) **بدون تغییر Schema**.
Email Verification Gate (G11/D-8) و قواعد Google (G12–G21) تغییر نمی‌کنند. تغییر Email کاربر (Change Email) و Set Password از Profile **در این فاز نیست**.

## ۲. قواعد

| کد | قاعده |
|---|---|
| **E1** | **نرمال‌سازی:** Email همیشه `trim + lowercase` می‌شود (Register، Login، Forgot، Reset). تطبیق Case-insensitive است (`LOWER(email)`) و به Collation دیتابیس وابسته نیست؛ ردیف‌های قدیمیِ Mixed-case همچنان وارد می‌شوند/بازیابی می‌شوند. یکتایی Register هم Case-insensitive و شامل Soft-deleted است. کلید Rate Limit (Login per-email، Reset per-email) روی Email نرمال‌شده است؛ `A@x.com` و `a@x.com` یک سقف مشترک دارند. |
| **E2** | **Reset موفق** (Token معتبر ⇒ کنترل صندوق Email اثبات شده): رمز جدید ست می‌شود (برای کاربر بدون رمز، مثل ساخته‌شده با Google، «اولین رمز» است — G19)، `remember_token` عوض می‌شود، **Email تأییدنشده ⇒ تأییدشده**، و Sessionهای User ابطال می‌شوند (E4). Reset ناموفق (Token نامعتبر/منقضی/مصرف‌شده، رمز ضعیف) هیچ چیز را تغییر نمی‌دهد و Email را تأیید نمی‌کند. Token یک‌بارمصرف است. |
| **E3** | **تأیید با لینک امضاشده** Idempotent است؛ فقط گذار واقعی «تأییدنشده ⇒ تأییدشده» Audit می‌شود. |
| **E4** | **ابطال Session:** پس از Reset همه‌ی Sessionهای User حذف می‌شوند (ضد Pre-hijack: مهاجمی که با Email قربانی ثبت‌نام کرده بود، Session‌اش نمی‌ماند). فقط با `SESSION_DRIVER=database` عملی است؛ با Driver دیگر No-op است (فقط `remember_token` عوض می‌شود) ⇒ **Production باید `database` باشد** (پیش‌فرض `.env.example`). |
| **E5** | **بدون Enumeration جدید:** پاسخ Forgot همیشه یکسان است؛ پیام شکست Login همان پیام عمومی است. (پیام «این ایمیل قبلاً ثبت شده» در Register از قبل وجود داشته و تغییر نکرده — D-11 باز.) |
| **E6** | **Core مالک منطق است:** `Identity\EmailIdentity` (نرمال‌سازی/تطبیق) و `Identity\EmailAuthService` (اثرات Reset/Verify)؛ کنترلرهای Website فقط هماهنگ می‌کنند. |

## ۳. Audit
`identity.password_reset` (`after.first_password = true|false`) · `identity.email_verified` (`after.method = link | password_reset`). Actor = همان User. Email/رمز/Token هرگز در Audit نمی‌آید.

## ۴. تصمیم‌های باز برای صاحب پروژه
| کد | سؤال | پیش‌فرض فعلی |
|---|---|---|
| D-11 | پیام Register برای Email تکراری (Enumeration) | بدون تغییر: «این ایمیل قبلاً ثبت شده است.» |
| D-12 | Change Email با تأیید مجدد (و اثرش روی هویت Google) | خارج از B2.2؛ فاز جدا |
| D-13 | Set Password از Profile برای کاربر Google (به‌جای فقط Reset) | خارج از B2.2؛ B2.4 |

## ۵. Verification
تست‌ها: `tests/Feature/Website/EmailAuthTest.php`. اجرا روی SQLite سبز؛ MariaDB/MySQL و Staging هنوز لازم است.
