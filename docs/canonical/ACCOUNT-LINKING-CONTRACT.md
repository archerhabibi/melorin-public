# Melorin — Account Linking Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 1.0 |
| **وضعیت** | CANONICAL (Contract مستقل طبق Master §19) |
| **Parent** | Master Architecture Contract · Website Architecture Contract · `GOOGLE-SIGNIN-CONTRACT.md` · `EMAIL-AUTH-CONTRACT.md` |
| **فاز** | B2.4 (Release 3.3.12) |
| **بستن** | D-13 («Set Password از Profile») |

## ۱. دامنه
اتصال و جداسازی روش‌های هویتیِ **یک User واردشده** از صفحه‌ی Profile: **Google** و **Telegram**، به‌علاوه‌ی تعیین **اولین رمز** برای کاربر بدون رمز. خارج از دامنه: Merge دو User، Change Email (D-12)، تغییر رمز موجود، Telegram Identity Bridge (T2)، Google روی دامنه‌ی اختصاصی (D-10).

تفاوت با ورود (`ExternalIdentityService`): آنجا یک هویت بیرونی به User *Resolve* می‌شود (و Email نقش دارد)؛ اینجا User از قبل احراز شده و **صریحاً** می‌خواهد روشی را به حساب خودش وصل/جدا کند.

## ۲. قواعد

| کد | قاعده |
|---|---|
| **L1** | منطق فقط در Core (`Identity\AccountLinkingService`)؛ Website فقط Session/Redirect/پیام. Core فقط `reason` ماشین‌خوان می‌دهد. |
| **L2** | **هرگز Merge.** هویتی که به User دیگری وصل است رد می‌شود (Google: `identity.google_link_rejected`؛ Telegram: `identity.telegram_link_rejected_owned_by_other`). |
| **L3** | هر User حداکثر یک Google و یک Telegram. **جایگزینی بی‌صدا ممنوع**: اگر User هویتی از همان Provider دارد، ابتدا باید Unlink کند (قبلاً callback تلگرام `telegram_id` موجود را بی‌صدا بازنویسی می‌کرد؛ رفع شد). اتصال دوباره‌ی همان هویت Idempotent است (بدون رد، بدون Audit رد). |
| **L4** | کلید Google همچنان `(provider, sub)` است (G13). Email حساب Google می‌تواند با Email حساب فرق کند و هیچ‌وقت Email حساب را عوض نمی‌کند. Email Google باید `email_verified = true` باشد. فقط User `active` می‌تواند وصل کند. |
| **L5** | **Unlink عملیات حساس است:** اگر User رمز دارد باید `current_password` بدهد (Session دزدیده‌شده به‌تنهایی کافی نیست)؛ اشتباه ⇒ هیچ تغییری. User بدون رمز (فقط Google) چیزی برای تأیید ندارد. POST + CSRF + `throttle:5,1`. |
| **L6** | **ضد Lock-out.** روش‌های ورود Website = {رمز، Google}. Unlink Google فقط با داشتن رمز مجاز است. Unlink Telegram فقط وقتی مجاز است که User **Email** و حداقل یک روش ورود داشته باشد (User ربات بدون Email با Unlink از حساب و Wallet خودش بیرون می‌ماند). رد ⇒ Audit `*_unlink_rejected`. |
| **L7** | **اتصال Google** (callback ثابت B2.1، G15): شروع از `GET /identity/google/link` (فقط `auth`، `throttle:10,1`، 404 بدون Google). Session `google_oauth` با `mode = link` و `user_id` **سمت سرور**؛ مقصد برگشت و خطاها همیشه Profile فروشگاه مبدأ. callback: نشست `link` برای کاربر دیگر/بدون کاربر واردشده ⇒ رد بدون اثر؛ کاربر واردشده بدون نشست `link` ⇒ Home؛ state/nonce/PKCE/TTL/یک‌بارمصرف مثل G12/G18. |
| **L8** | **اتصال Telegram** همچنان فقط Main Context، فقط User واردشده، HMAC رسمی + `auth_date` ≤ ۲۴ساعت + `state` یک‌بارمصرف (Master G10). تصمیم مالکیت/جایگزینی به Core منتقل شد. |
| **L9** | **اولین رمز (D-13):** `POST /identity/password` فقط برای User **بدون رمز** با Email **تأییدشده**، با `Password::defaults()` و تأیید رمز. هرگز رمز موجود را بازنویسی نمی‌کند (تغییر رمز در این فاز نیست). `remember_token` عوض می‌شود. |
| **L10** | هیچ CustomerAccount/Wallet/Order ای ساخته نمی‌شود (R7؛ G21 فقط برای *ورود* است). |
| **L11** | پس از Unlink Telegram، ربات با `/start` برای آن Telegram ID یک User **جدید** می‌سازد (Merge وجود ندارد)؛ Wallet قبلی در حساب Website می‌ماند. UI این را هشدار می‌دهد. Re-link همان Telegram به حساب قدیمی تا زمانی که حساب جدید ربات وجود دارد رد می‌شود (L2). |

## ۳. Audit
`identity.google_linked` (`after.method = profile`) · `identity.google_link_rejected` (`after.reason` [+ `owned_by_user_id`]؛ **بدون Email**) · `identity.google_unlinked` · `identity.google_unlink_rejected` · `identity.telegram_linked` · `identity.telegram_link_rejected_owned_by_other` · `identity.telegram_link_rejected` (`after.reason`) · `identity.telegram_unlinked` · `identity.telegram_unlink_rejected` · `identity.password_set` (`after.method = profile`). Actor = همان User.

## ۴. Routeها
`website[.store].identity.google.link` (GET) · `.identity.google.unlink` · `.identity.telegram.unlink` · `.identity.password.set` (POST) — همه پشت `auth` + `store.customer`. callback ثابت `auth.google.callback` دیگر پشت `guest` نیست؛ رفتار برای کاربر واردشده داخل کنترلر enforce می‌شود (L7).

## ۵. تصمیم‌های باز
| کد | سؤال | پیش‌فرض فعلی |
|---|---|---|
| D-14 | آیا کاربر ربات (بدون Email) بتواند از Website یک Email/رمز اضافه کند تا بعداً Telegram را جدا کند؟ | خارج از B2.4 (نیاز به Change/Add Email = D-12) |
| D-15 | Merge دو User (Telegram کاربر ربات + Google کاربر وب) | ممنوع تا T2 (Identity Bridge) |
| D-16 | تغییر رمز موجود از Profile | ✅ بسته شد در B2.5 (`SESSION-SECURITY-CONTRACT.md` §S9) |

## ۶. Verification
`tests/Feature/Website/AccountLinkingTest.php`. **`TESTED` فقط با اجرای واقعی سبز**؛ Google/Telegram واقعی فقط در Staging قابل تأیید است.
