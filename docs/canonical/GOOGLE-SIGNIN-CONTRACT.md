# Melorin — Google Sign-In Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 1.2 (G21: ورود Google و ورود Email+Password هر دو CustomerAccount می‌سازند) |
| **وضعیت** | CANONICAL (Contract مستقل طبق Master §19: «فعال‌سازی هرکدام = Contract مستقل + Verification») |
| **Parent** | Master Architecture Contract 2.8 · Website Architecture Contract 1.9 |
| **فاز** | B2.1 (Release 3.3.9) |
| **اثر بر D-6** | D-6 («حذف از Release اول») **بازگشایی و با همین سند بسته شد**. حذف از Release اول تاریخی است؛ از 3.3.9 Google Sign-In فعال و **Opt-in با Config** است. |

## ۱. دامنه
Google Sign-In **روش احراز هویت** است و علاوه بر آن (G21) **CustomerAccount** کاربر را در فروشگاه مبدأ ورود می‌سازد؛ Wallet و Order را هرگز نمی‌سازد.
`User ≠ CustomerAccount` (R2). R7 («CustomerAccount فقط در لحظه‌ی خرید») با **تصمیم صاحب پروژه** یک استثنای صریح و محدود دارد: ورود موفق با Google و ورود موفق با Email+Password (G21؛ منطق مشترک `LoginMembershipService`). هیچ مسیر دیگری (GET، Guest، Register) CustomerAccount نمی‌سازد.
Account Linking برای کاربر واردشده (Google/Telegram از Profile) = B2.4؛ Telegram Identity Bridge = T2؛ خارج از این سند.

## ۲. قواعد

| کد | قاعده |
|---|---|
| **G12** | پروتکل: OIDC Authorization Code + **PKCE (S256)** + `state` (یک‌بارمصرف، Session-bound) + `nonce`. Exchange فقط Server-to-Server (TLS + `client_secret`). `id_token` اعتبارسنجی می‌شود: `iss`، `aud`، `exp`، `iat`، `nonce`، `sub`، `email`، `email_verified`. Socialite/وابستگی جدید استفاده نمی‌شود. توکن/Secret هرگز در URL، Log یا Audit نمی‌آید. |
| **G13** | کلید هویت = `(provider, sub)` در جدول `user_identities` (`UNIQUE(provider, provider_user_id)` و `UNIQUE(user_id, provider)`). **Email کلید نیست**؛ فقط Snapshot است. |
| **G14** | ترتیب Resolve (Core: `ExternalIdentityService`): (۱) هویت موجود ⇒ ورود. (۲) `email_verified ≠ true` ⇒ رد. (۳) User محلی با همان Email (Case-insensitive): حذف‌شده/غیرفعال ⇒ رد؛ **Email محلی تأییدنشده ⇒ رد** (ضد Pre-hijack)؛ `auto_link` خاموش ⇒ رد؛ وگرنه Link + ورود. (۴) در غیر این صورت User جدید: `email_verified_at = now`، `password = NULL`، `joined_from = website`. |
| **G15** | Redirect URI **ثابت** و فقط روی Context اصلی (`GET /auth/google/callback`). شروع در هر دو Context (`/auth/google` و `/store/{slug}/auth/google`)؛ مقصد برگشت (Login URL، ادامه‌ی خرید Guest، `url.intended` هم‌Host، Home فروشگاه) **سمت سرور** در Session ذخیره می‌شود؛ هیچ URL بازگشتی از Client پذیرفته نمی‌شود. |
| **G16** | **G6 برقرار است:** ورود/ثبت‌نام با Google همان خرید Pending را ادامه می‌دهد؛ User/CustomerAccount/Purchase از **داده‌ی Guest** ساخته نمی‌شود (G3)؛ CustomerAccount فقط از خودِ ورود Google و برای User واردشده ساخته می‌شود (G21). Email فرم Guest همچنان Proof نیست (R9/G7)؛ تطبیق Email فقط از مسیر Email **تأییدشده‌ی Google** ⇄ Email **تأییدشده‌ی محلی** مجاز است. |
| **G17** | فقط `status = active`. User غیرفعال/مسدود/Soft-deleted هرگز وارد نمی‌شود و ساخته/Link نمی‌شود. (همین Gate برای Login با رمز هم اعمال شد.) |
| **G18** | Session: `regenerate()` پس از ورود؛ `google_oauth` یک‌بارمصرف (`pull`) با TTL ۶۰۰ ثانیه. Rate Limit: ۱۰ درخواست/دقیقه روی شروع و callback. شکست‌ها Fail-closed با پیام عمومی؛ بدون ساخت User در هر مسیر خطا. |
| **G19** | User بدون رمز: Login با رمز برای او همان پیام عمومی و همان Rate Limit را دارد (بدون 500/Enumeration)؛ می‌تواند از Password Reset رمز تعیین کند. Email Verification Gate (G11) برای او برقرار است و چون Email تأییدشده است مسدود نمی‌شود. |
| **G20** | Feature Flag: بدون `GOOGLE_CLIENT_ID` و `GOOGLE_CLIENT_SECRET`، دکمه نمایش داده نمی‌شود و هر دو Route **404** می‌دهند. `GOOGLE_AUTO_LINK_VERIFIED_EMAIL=false` ⇒ کاربر موجود باید ابتدا با رمز وارد شود. |
| **G21** | **CustomerAccount از ورود Google (تصمیم صاحب پروژه، استثنای R7):** پس از هر نتیجه‌ی موفق (`login` / `registered` / `linked`) Core همان User را در **فروشگاه مبدأ** Resolve می‌کند: موجود ⇒ همان (بدون تغییر Status/نام)، نبود ⇒ ساخته می‌شود (`status = active`، `display_name = full_name`). فروشگاه مبدأ در لحظه‌ی شروع (`/auth/google` ⇒ اصلی، `/store/{slug}/auth/google` ⇒ همان نماینده) **سمت سرور** در Session نگه داشته می‌شود و از Query/Client پذیرفته نمی‌شود. **همین قاعده عیناً برای ورود موفق با Email+Password هم برقرار است** (Context همان درخواست: `/login` ⇒ اصلی، `/store/{slug}/login` ⇒ همان نماینده) تا دو روش ورود رفتار یکسان داشته باشند. ساخت فقط از `Store\IdentityService::resolveCustomerAccount` از طریق `Identity\LoginMembershipService` (Idempotent، ضد Race). نماینده‌ی ناموجود/غیرفعال ⇒ ورود انجام می‌شود ولی عضویت ساخته نمی‌شود. مسیر رد‌شده (G14/G17) هرگز CustomerAccount نمی‌سازد. شکست ساخت عضویت ورود را نمی‌شکند (Checkout همچنان Lazy Resolve می‌کند). CustomerAccountهای فروشگاه‌های مختلف Merge نمی‌شوند (R8). Wallet/Order ساخته نمی‌شود. |

## ۳. Audit
`identity.google_registered` · `identity.google_login` · `identity.google_linked` (`after.method = verified_email`) · `identity.google_customer_account_created` / `identity.password_customer_account_created` (فقط وقتی عضویت واقعاً ساخته شد؛ `after = {store_type, reseller_id}`؛ Target = CustomerAccount) · `identity.google_rejected` (`after.reason`؛ **بدون Email/PII**). Actor = همان User (به‌جز Rejected).

## ۴. تصمیم‌های باز برای صاحب پروژه
| کد | سؤال | پیش‌فرض فعلی |
|---|---|---|
| D-9 | Link خودکار Google به User محلی با Email **تأییدشده** (G14) یا الزام ورود با رمز؟ | Link خودکار (`GOOGLE_AUTO_LINK_VERIFIED_EMAIL=true`)؛ با Flag قابل‌تغییر |
| D-10 | Google روی دامنه‌ی اختصاصی نماینده (B6.1) | خارج از این سند؛ هر دامنه Redirect URI ثابت جدا در Google Console می‌خواهد |

## ۵. Verification
در `VERIFICATION-MATRIX.md` ثبت است. تست‌ها: `tests/Feature/Website/GoogleLoginTest.php`. **`TESTED` فقط با اجرای واقعی سبز.** `GoogleLoginTest` (۴۰ تست، شامل G21) روی SQLite اجرا و سبز شد؛ اجرای MariaDB/MySQL و Staging هنوز لازم است. Google واقعی (Console، Redirect URI، Consent Screen) فقط در Staging قابل تأیید است.
