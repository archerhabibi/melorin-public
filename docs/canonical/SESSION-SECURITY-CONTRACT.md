# Melorin — Session Security Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 1.0 |
| **وضعیت** | CANONICAL (Contract مستقل طبق Master §19) |
| **Parent** | Master Architecture Contract · Website Architecture Contract (§Session Security) · `EMAIL-AUTH-CONTRACT.md` · `ACCOUNT-LINKING-CONTRACT.md` |
| **فاز** | B2.5 (Release 3.3.13) |

## ۱. دامنه
امنیت **نشست کاربر Website** (Guard `web`): چه چیزی یک نشست را معتبر نگه می‌دارد، چه وقت باید بسته شود، و کاربر چگونه نشست‌های خودش را ببیند و ببندد. خارج از دامنه: نشست پنل‌های Filament (Admin/Reseller؛ Guard جدا و `AuthenticateSession` خودشان)، 2FA/Passkey، اعلان «ورود از دستگاه جدید»، سقف تعداد نشست هم‌زمان، Step-up Authentication برای عملیات مالی (فازهای بعد).

## ۲. آنچه از قبل برقرار بود (B2.1–B2.4 / فاز ۸) و دست نخورد
Regenerate پس از Login/Register/Google؛ `invalidate()` + `regenerateToken()` در Logout؛ ابطال همه‌ی نشست‌ها پس از Reset رمز (E2/E4)؛ Cookie `Secure` (Preflight)، `HttpOnly`، `SameSite=lax`؛ CSRF؛ Rate Limit ورود؛ ورود کاربر غیرفعال بسته است (`status=active`).

## ۳. قواعد

| کد | قاعده |
|---|---|
| **S1** | منطق در Core (`Identity\SessionSecurityService`، `AccountLinkingService`)؛ Website فقط Channel است (Middleware/فرم/پیام). |
| **S2** | **ابطال دستگاه‌های دیگر مستقل از Driver:** هر مسیر Website پشت `auth` پشت `AuthenticateSession` لاراول است؛ تغییر Hash رمز ⇒ نشست‌های دیگر با اولین درخواست بسته می‌شوند. با `SESSION_DRIVER=database` علاوه بر آن سطرها فوراً حذف می‌شوند. |
| **S3** | **کاربر غیرفعال/مسدود نشست زنده ندارد.** Middleware `website.session` در هر درخواست، `status != active` را می‌بندد (قبلاً فقط «ورود جدید» بسته می‌شد). Audit: `identity.session_terminated` (`after.reason = user_not_active`). |
| **S4** | **سقف مطلق عمر نشست:** `SESSION_ABSOLUTE_LIFETIME` (دقیقه، پیش‌فرض ۱۰۰۸۰ = ۷ روز، `0` = غیرفعال) از لحظه‌ی ورود، مستقل از فعالیت. نشست «مرا به‌خاطر بسپار» مستثناست. لحظه‌ی ورود با Listener روی رویداد `Login` در Session ثبت می‌شود (`auth.started_at`، `auth.remembered`)؛ نشست بدون مُهر (پیش از B2.5) از اولین درخواست حساب می‌شود، نه بسته. Audit: `after.reason = absolute_timeout`. بستن با `logoutCurrentDevice` است تا `remember_token` (و Cookieهای دستگاه‌های دیگر) نچرخد. |
| **S5** | **مدیریت نشست‌ها (Profile):** فهرست نشست‌های فعال (دستگاه، IP ماسک‌شده، آخرین فعالیت)، بستن یکی، بستن همه‌ی دیگر. فقط `SESSION_DRIVER=database`؛ در غیر این صورت کارت نمایش داده نمی‌شود. POST + CSRF + `throttle:5,1` (به‌ازای کاربر). نشست فعلی از این مسیر بسته نمی‌شود (برای آن «خروج» هست). |
| **S6** | **تأیید هویت:** کاربر دارای رمز باید رمز فعلی بدهد (هم‌راستا با L5)؛ کاربر فقط-Google چیزی برای تأیید ندارد. |
| **S7** | **Session ID هرگز در HTML/URL/Audit نمی‌آید.** فرم‌ها فقط `handle` مبهم دارند: `HMAC-SHA256(session_id, APP_KEY)` (۳۲ کاراکتر). handle نشست دیگری/ناموجود ⇒ خطای بی‌اثر. |
| **S8** | **مالکیت از روی payload اثبات می‌شود، نه فقط `sessions.user_id`.** Filament با `shouldUse(guard)` گارد پیش‌فرض را در پنل‌ها عوض می‌کند و `user_id` نشست Admin/Reseller هم پر می‌شود؛ id آن‌ها با id یک User عادی برخورد می‌کند. فهرست و بستن تکی **Fail-closed** است (فقط نشستی که کلید `login_web_*` آن برابر User است). ابطال کامل (`revokeAll`) **Fail-open** است: payload ناخوانا هم حذف می‌شود؛ فقط نشستِ ثابت‌شده‌ی Guard دیگر دست‌نخورده می‌ماند. |
| **S9** | **تغییر رمز موجود (D-16 بسته شد):** `POST /identity/password/update` با `existing_password` + رمز جدید (`Password::defaults()` + تأیید) برای کاربر **دارای رمز**؛ رمز فعلی در Core تأیید می‌شود؛ رمز جدید باید فرق کند. موفق ⇒ `remember_token` جدید + بستن همه‌ی نشست‌های دیگر + `logoutOtherDevices` (Hash نشست فعلی و Cookie «به‌خاطر بسپار» همین دستگاه به‌روز می‌شود). تعیین **اولین** رمز (L9) هم نشست‌های دیگر را می‌بندد. |
| **S10** | **عمر Cookie «مرا به‌خاطر بسپار»:** `auth.guards.web.remember` = `AUTH_REMEMBER_MINUTES` (پیش‌فرض ۴۳۲۰۰ = ۳۰ روز؛ پیش‌فرض لاراول ۴۰۰ روز است). |
| **S11** | **Preflight** (`melorin:preflight config`): `session_driver` (array/cookie در Production ⇒ FAIL؛ file ⇒ WARN)، `session_http_only` (FAIL)، `session_same_site` (`none` و `strict` ⇒ FAIL در Production؛ `strict` برگشت Google/Telegram را می‌شکند)، `session_lifetime` (Idle > ۷۲۰ دقیقه، سقف مطلق ۰ یا > ۳۰ روز ⇒ WARN)، `session_domain` (تنظیم‌شده ⇒ WARN؛ Cookie باید فقط-میزبان باشد، پیش‌نیاز دامنه‌ی اختصاصی نماینده‌ها در B6). |

## ۴. Audit
`identity.sessions_revoked` (`after`: `scope = others|one`، `count`، `reason = user_request|password_changed|password_set`؛ **بدون IP/UA/Session ID**) · `identity.session_terminated` (`after.reason = user_not_active|absolute_timeout`) · `identity.password_changed` · `identity.password_change_rejected` (`after.reason = wrong_password`). Actor = همان User. رمز و Hash هرگز نوشته نمی‌شوند.

## ۵. Routeها
`website[.store].identity.sessions.revoke` · `.identity.sessions.revoke-others` · `.identity.password.update` (POST، پشت `auth` + `AuthenticateSession` + `store.customer`). Middleware `website.session` روی هر چهار گروه Route Website (Main، Reseller، Verify، Google callback).

## ۶. تصمیم‌های باز
| کد | سؤال | پیش‌فرض فعلی |
|---|---|---|
| D-17 | اعلان Email «ورود از دستگاه جدید» | خارج از B2.5 (نیاز به Fingerprint/Mailable) |
| D-18 | سقف تعداد نشست هم‌زمان (حذف قدیمی‌ترین) | ندارد |
| D-19 | Re-auth (Step-up) برای عملیات مالی حساس (شارژ/خرید) | ندارد |
| D-20 | `__Host-` Prefix برای نام Cookie | انجام نشد (نیاز به هماهنگی با Filament و `SESSION_COOKIE`) |
| D-21 | بستن نشست‌ها هنگام Unlink Google/Telegram | انجام نشد؛ فقط تعیین/تغییر رمز و Reset |

## ۷. Verification
`tests/Feature/Website/SessionSecurityTest.php` (نشست واقعی database + Cookie؛ بدون `actingAs`) و `tests/Feature/Operations/PreflightTest.php`. اجرای واقعی با Redis/File Driver و چند مرورگر فقط در Staging قابل تأیید است.
