# B2.5 — Session Security

**نسخه:** 3.3.13 · **Contract:** `docs/canonical/SESSION-SECURITY-CONTRACT.md` (جدید) · **Schema:** بدون تغییر.

## وضعیت قبل
نشست‌ها پایه‌ی درست داشتند (Regenerate پس از ورود، Invalidate در خروج، ابطال پس از Reset رمز، Cookie امن) اما: (۱) کاربری که پس از ورود مسدود می‌شد تا انقضای Idle (۱۲۰ دقیقه‌ی لغزان) کار می‌کرد؛ (۲) سقف مطلق عمر نشست نبود؛ (۳) کاربر دارای رمز راهی برای **تغییر** رمز نداشت (فقط «فراموشی رمز»)؛ (۴) کاربر نشست‌های خودش را نمی‌دید و نمی‌توانست ببندد؛ (۵) ابطال دستگاه‌ها فقط با Driver دیتابیس ممکن بود؛ (۶) Cookie «به‌خاطر بسپار» ۴۰۰ روز اعتبار داشت؛ (۷) Preflight هیچ چک Session نداشت جز `Secure`.

## آنچه اضافه/بهتر شد
| # | تغییر | محل |
|---|---|---|
| 1 | Core: `SessionSecurityService` (فهرست، بستن همه‌ی دیگر، بستن یکی با handle مبهم، `revokeAll`) + DTO `ActiveSession`؛ مالکیت از payload اثبات می‌شود (S8) | `app/Services/Core/Identity/` |
| 2 | `EmailAuthService::revokeSessions` به سرویس جدید واگذار شد (API ثابت) و دیگر نشست Admin/Reseller با id برخوردکننده را نمی‌بندد | `EmailAuthService` |
| 3 | Middleware `website.session`: کاربر غیرفعال ⇒ خروج (S3)، سقف مطلق (S4) | `Website/Http/Middleware/EnforceSessionPolicy` |
| 4 | Listener روی `Login` مُهر شروع نشست را می‌نویسد (همه‌ی مسیرهای ورود، بدون دست‌زدن به Controllerها) | `Website/Listeners/StampSessionLogin` |
| 5 | `AuthenticateSession` روی مسیرهای `auth` Website ⇒ ابطال دستگاه‌های دیگر مستقل از Driver (S2) | `routes/website.php` |
| 6 | تغییر رمز موجود (D-16) + بستن نشست‌های دیگر؛ اولین رمز هم نشست‌های دیگر را می‌بندد | `AccountLinkingService::changePassword/setFirstPassword`, `AccountLinkingController::updatePassword` |
| 7 | Profile: «دستگاه‌ها و نشست‌های فعال» (IP ماسک، برچسب دستگاه، بستن یکی/همه) و فرم تغییر رمز | `identity/profile.blade.php`, `SessionController`, `DeviceLabel` |
| 8 | `SESSION_ABSOLUTE_LIFETIME` و `AUTH_REMEMBER_MINUTES` (۳۰ روز به‌جای ۴۰۰) | `config/session.php`, `config/auth.php`, `.env.example` |
| 9 | Preflight: `session_driver`، `session_http_only`، `session_same_site`، `session_lifetime`، `session_domain` | `Services/Ops/Preflight.php` |
| 10 | **رفع تست B2.3:** `login_and_register_remind_the_guest...` به‌خاطر ماندگاری `withCookie` روی نمونه‌ی تست، در تکرار دوم حلقه شکست می‌خورد؛ باگ تست بود نه برنامه | `GuestIdentityFlowTest` |

## عمداً انجام نشد
اعلان «دستگاه جدید» (D-17)، سقف نشست هم‌زمان (D-18)، Step-up برای عملیات مالی (D-19)، `__Host-` Prefix (D-20)، بستن نشست‌ها هنگام Unlink Google/Telegram (D-21)، نشست پنل‌های Filament.

## ریسک‌ها و نکات استقرار
- **SameSite=strict** ورود Google و اتصال Telegram را می‌شکند؛ Preflight آن را FAIL می‌کند.
- پس از استقرار، نشست‌های موجود بدون مُهر از اولین درخواست حساب می‌شوند؛ هیچ‌کس بیرون انداخته نمی‌شود. اولین بار، `AuthenticateSession` Hash رمز را در نشست ذخیره می‌کند.
- کاربری که «مرا به‌خاطر بسپار» زده، بیش از ۳۰ روز بی‌فعالیت بماند باید دوباره وارد شود (قبلاً ۴۰۰ روز).
- با `SESSION_DRIVER=file/redis` کارت «نشست‌های فعال» نمایش داده نمی‌شود؛ ابطال با `AuthenticateSession` همچنان کار می‌کند.

## تأیید
`SessionSecurityTest` (۳۵ تست) و ۲ تست Preflight جدید؛ روی SQLite اجرا و سبز شد. کل مجموعه: ۶۸۴ سبز، ۵ Skip (Redis واقعی)، ۰ شکست. چند «جهش» (حذف `AuthenticateSession`، حذف چک مالکیت، حذف Middleware، حذف استثنای نشست فعلی) هرکدام دست‌کم یک تست را شکستند.
