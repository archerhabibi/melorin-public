# فاز W4 (بخش دوم) — Renewal + Referral/Commission (v3.2.12)

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W4، آیتم‌های ۴ تا ۷ + تصمیم صریح
شما در همین گفتگو: **«همه دقیقاً همانند Core و ربات تلگرام باید
باشند؛ Refund/Retry کاملاً Admin-only می‌مانند و Website فقط نتیجه
را نشان می‌دهد.»**

پچ متناظر: `melorin-website-w4-part2.patch` (روی
`melorin-website-w4-part1.patch` اعمال می‌شود).

> **یادداشت ادغام**: این پچ اصلاً به‌عنوان نسخه‌ی ۳.۲.۵ روی مبنای
> ۳.۲.۴ (نسخه‌ی اول نفر ۲) نوشته شده بود. تا رسیدنش، پچ‌های ۳.۲.۵ تا
> ۳.۲.۱۱ (نفر ۱، نفر ۴، و merge اول نفر ۲) منتشر شده بودند، پس شماره
> به ۳.۲.۱۲ تغییر کرد؛ بدون تغییر منطقی در کد.

## بررسیِ قبل از پیاده‌سازی: ربات تلگرام واقعاً چه‌کار می‌کند؟

قبل از نوشتن کد، کل `app/Channels/TelegramBot` و
`app/Channels/ResellerBot` برای «refund» و «retry» جست‌وجو شد:

```
grep -rln "refund|retry|Refund|Retry" app/Channels/TelegramBot app/Channels/ResellerBot --include=*.php
→ صفر نتیجه
```

یعنی **نه ربات اصلی نه ربات نمایندگی، هیچ‌وقت هیچ دکمه‌ای برای درخواست
Refund یا Retry به مشتری نشان نداده‌اند** — این دو از همان ابتدا
Admin-only بوده‌اند (فقط `OrderResource` در پنل ادمین این دکمه‌ها را
دارد). پس تصمیم شما دقیقاً همان رفتار موجود را برای Website هم تثبیت
می‌کند، نه یک محدودیت تازه.

در مقابل، Renewal **دقیقاً برعکس** است:
`AccountsHandler::renew()` در ربات اصلی مستقیماً توسط مشتری صدا زده
می‌شود (بدون هیچ واسطه‌ی Admin). پس طبق همون اصل «دقیقاً همانند Core و
ربات»، Renewal باید روی Website هم یک اکشن واقعیِ سمتِ مشتری باشد، نه
فقط نمایش.

## چه چیزی ساخته شد

### ۱) Renewal (بند ۴) — کپیِ دقیقِ منطق ربات

`Account\AccountsController::renew()` سطر‌به‌سطر همان سه بخش
`AccountsHandler::renew()` (ربات اصلی) را با HTTP redirect به‌جای
پیام تلگرام تکرار می‌کند:

| بخش | ربات | Website |
|---|---|---|
| پیش‌بررسی UX (نه دروازه‌ی واقعی) | پیام مستقیم | `session('renewal_error')` |
| صدا زدن Core | `RenewalService::renew($account)` | همان، بدون تغییر |
| `InsufficientBalanceException` | «موجودی کیف پول کافی نیست.» | همان متن، عیناً |
| `RenewalFailedException` | `{$e->getMessage()}\n{$e->customerNotice()}` | همان، عیناً (همان `customerNotice()` از `CarriesFailureOutcome`) |
| `\RuntimeException` عمومی | «لطفاً با پشتیبانی تماس بگیرید…» | همان متن، عیناً |
| موفقیت | موجودی قبل/بعد | همان دو عدد، در `session('renewal_success')` |

هیچ Exception جدیدی معرفی نشد، هیچ بازگشت‌وجه دستی‌ای اضافه نشد —
دقیقاً همان محافظه‌کاریِ کامنت خودِ `AccountsHandler::renew()»
(«بازگشت خودکار در این حالت خطرناک است») اینجا هم رعایت شده.

### ۲) Referral/Commission (بند ۵) — کپیِ دقیقِ منطق ربات

`Account\ReferralController::show()` هم‌مضمون با
`MiscHandler::referral()`:

- لینک دعوت (اینجا `?ref={user_id}&` روی `register`، چون Website
  کوئری‌استرینگ دارد نه Telegram deep-link) + تعداد زیرمجموعه‌ها
  (`User::referredUsers()->count()` — سطح User، نه Context، دقیقاً
  مثل ربات).
- فقط پاداشی که واقعاً پرداخت می‌شود تبلیغ می‌شود (همان احتیاطِ صریحِ
  کامنت ربات: «قبلاً وعده‌ی کمیسیون هم می‌داد که پرداخت نمی‌شد»).
- برخلاف تعداد زیرمجموعه‌ها، فهرست **کمیسیون‌های پرداخت‌شده**
  Context-scoped است (`referrer_customer_account_id`، نه `referrer_id`
  خام) — طبق بند ۳۱ زیرسند و `CommissionAndBridgeTest` («کمیسیون به
  کیف‌پول همان Contextی که خرید در آن رخ داده پرداخت می‌شود»).

### ۳) Refund UI / Retry UI (بند ۶، ۷) — بسته شد، بدون کد جدید

هیچ Route، دکمه، یا Controller جدیدی برای این دو اضافه نشد. آنچه از
قبل در پچ اول (فهرست سفارش‌ها، `Order::statusLabels()`) وجود دارد
همین الان دقیقاً همان چیزی است که باید باشد:

- سفارش با `status=provision_failed` → «ساخت ناموفق — نیازمند رسیدگی»
  (بدون هیچ دکمه‌ی Retry).
- سفارش با `status=refunded` → «بازگشت‌شده» (بدون هیچ دکمه‌ی Refund).

این دقیقاً هم‌رفتار با ربات است: مشتری فقط نتیجه را می‌بیند، تصمیم و
اقدام همیشه دست ادمین (`OrderResource`) می‌ماند.

## نکته‌ی باز (خارج از مالکیت این پچ)

`RegisteredUserController` (ثبت‌نام سایت) هنوز پارامتر `?ref=` را
نمی‌خواند و `referrer_id` کاربر جدید را ثبت نمی‌کند — یعنی لینک دعوتی
که این پچ نمایش می‌دهد، فعلاً «قابل‌کپی» است ولی «مؤثر» نیست. این فایل
خارج از مالکیت `Controllers/Account/` است (فاز W0/W1، احتمالاً نفر ۱)
و باید با صاحبش هماهنگ شود، نه اینکه اینجا بی‌سروصدا تغییر کند.

## اجرا

```
php artisan test --filter=AccountPanelPart2Test
```

بدون Migration جدید.
