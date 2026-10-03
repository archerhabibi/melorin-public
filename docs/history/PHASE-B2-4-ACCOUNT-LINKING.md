# B2.4 — Account Linking

**نسخه:** 3.3.12 · **Contract:** `docs/canonical/ACCOUNT-LINKING-CONTRACT.md` (جدید) · **Schema:** بدون تغییر.

## وضعیت قبل
Google فقط برای *ورود/ثبت‌نام* (B2.1) بود؛ کاربر واردشده راهی برای وصل‌کردن Google نداشت. Telegram فقط وصل می‌شد و Unlink نداشت. کاربر Google بدون رمز فقط با «فراموشی رمز» می‌توانست رمز بگیرد (D-13).

## آنچه اضافه/بهتر شد
| # | تغییر | محل |
|---|---|---|
| 1 | Core: `AccountLinkingService` + `AccountLinkResult` | `app/Services/Core/Identity/` |
| 2 | اتصال Google برای کاربر واردشده (`mode=link`) | `GoogleAuthController::linkRedirect/callback/finishLink` |
| 3 | Unlink Google و Telegram با تأیید رمز + ضد Lock-out | `AccountLinkingController` |
| 4 | اولین رمز از Profile (D-13) | `AccountLinkingController::setPassword` |
| 5 | **رفع باگ:** callback تلگرام `telegram_id` موجود را بی‌صدا عوض می‌کرد | `AccountLinkingService::linkTelegram` |
| 6 | Profile: وضعیت Email/رمز/Google/Telegram | `identity/profile.blade.php` |
| 7 | Middleware `guest` از callback ثابت Google برداشته شد؛ رفتار در کنترلر | `routes/website.php`, `GoogleAuthController` |

## عمداً انجام نشد
- Merge کاربر ربات و کاربر وب (T2 / D-15).
- Add/Change Email برای کاربر ربات (D-12/D-14)؛ بنابراین کاربر فقط-تلگرام Unlink نمی‌کند.
- تغییر رمز موجود (D-16).
- ساخت CustomerAccount هنگام Link (R7).

## ریسک شناخته‌شده
Unlink Telegram باعث می‌شود `/start` ربات حساب جدیدی بسازد (L11)؛ در UI هشدار داده شده است.

## تأیید
`tests/Feature/Website/AccountLinkingTest.php` (۳۵ تست) نوشته شده و **اجرا نشده**. پیش از merge: `php artisan test`.
