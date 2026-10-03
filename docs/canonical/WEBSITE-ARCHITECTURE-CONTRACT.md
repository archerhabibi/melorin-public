# Melorin — Website Architecture Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 1.9 |
| **وضعیت** | CANONICAL |
| **Parent Contract** | Master Architecture Contract 2.8 |
| **جایگزین** | Website Subdocument v1.2 (متن داخلی 1.1) و Website Contract 1.3 / 1.4 / 1.6 / 1.7 — DEPRECATED |
| **تاریخ اعتبار** | ۱۴۰۵/۰۷/۰۸ (۲۰۲۶-۰۹-۳۰) |
| **دامنه** | Main Website + Reseller Websites + Guest Checkout + Auth + Checkout + Payment (Wallet Charge) + Wallet + Orders + Renewal + Referral + Commission + Provisioning (نمایش) |

> در نسخه‌ی قبل بندهای ۹–۱۲ دو بار (با محتوای متفاوت) تکرار شده بودند و بندهای ۷، ۶۳، ۱۰۲، ۱۰۳ با بندهای ۸ و ۶۴ متناقض بودند. در این نسخه هر بند یک‌بار و مطابق Master آمده است. در نسخه‌ی 1.4 (فاز ۲) تصمیم Guest Form (Email تنها فیلد الزامی) اعمال و TBDهای Route/Store/Auth/Session/Rate-Limit با واقعیت کد Release 3.3.0 بسته شدند.

---

## 1. جایگاه
Website یک **Channel** است. تعارض ⇒ Master 2.7 برنده است:
`Master > Core Contract > Website Contract > Website Implementation`.

## 2. اصل بنیادین
Website مسئول UI، دریافت Input، نمایش Data، Session/Auth در حدود قرارداد و فراخوانی Core است. Website **نباید**: Pricing/Discount/Referral/Commission محاسبه کند، Wallet مدیریت کند، Purchase/Payment State/Provisioning مستقل داشته باشد، یا Store Scope را دور بزند.

```text
Input → Core (Facade) → Result → UI
```

## 3. Main و Reseller Website
هر دو از Core مشترک استفاده می‌کنند. هر Reseller Website: URL (`/store/{slug}`)، Branding (Name/Logo/Contact)، Menu، Customers، Products مجاز و Pricing اختصاصی دارد. کاربر در هر Website فقط در StoreContext همان Website فعال است، ولی می‌تواند Customer چند Store باشد.

## 4. هویت (ارجاع به Master بخش ۲)
`User ≠ CustomerAccount` · `CustomerAccount = User + StoreContext` · Reseller = User موجود + Context · **CustomerAccount فقط در لحظه‌ی خرید ساخته می‌شود** (GET هیچ CustomerAccount نمی‌سازد؛ `EnsureCustomerAccountResolved` فقط می‌خواند).

## 5. Guest Checkout (مطابق Master بخش ۳)

Guest نشست موقت شروع Checkout است؛ **خرید نهایی فقط بعد از Login/Register**.

```text
Product → «خرید به‌عنوان مهمان» → Guest Form (email* · name · phone)
  → GuestCheckout(pending) → Pending Page → Login/Register 
  → ادامه‌ی همان خرید → CustomerAccount (Lazy) → Checkout → Wallet Payment → Order → Provisioning
```

- ورود در ابتدای جریان الزامی نیست.
- فرم: `email` **تنها فیلد الزامی** (Validation فرمت)؛ `name` و `phone` **اختیاری**. (Master G2)
- **هیچ** User، CustomerAccount، Wallet یا Order از Guest ساخته نمی‌شود. تنها رکورد: `guest_checkouts` (`token, product_id, reseller_id, guest_email NOT NULL, guest_name nullable, guest_phone nullable, status, expires_at`).
- Token: تصادفی، در Cookie رمزنگاری‌شده، `Secure/HttpOnly/SameSite`، Expiration (پیش‌فرض ۴۵ دقیقه)، وابسته به StoreContext؛ استفاده‌ی Cross-Store رد می‌شود.
- **Pending Page** باید: اطلاعات ثبت‌شده را نشان دهد، وضعیت Pending را مشخص کند، Login/Register را برای ادامه‌ی همان خرید الزامی کند.
- Login/Register از Pending باید همان خرید را ادامه دهد و User/CustomerAccount/Purchase تکراری نسازد.
- اگر email (یا phone در صورت ارائه) با User موجود مطابقت داشت: هدایت به Login + Audit `identity.guest_collision_detected`؛ **هرگز Merge/Login خودکار**. (Email/Phone/Telegram ID/Cookie/IP/Session Proof نیستند.)
- Guest Menu با Customer Menu متفاوت است.

## 6. Authentication
فقط Email+Password (**IMPLEMENTED**). Google Sign-In از Release اول **حذف شد** (D-6). **Email Verification (IMPLEMENTED در فاز ۴):** Register یک Email تأیید می‌فرستد (لینک امضاشده، یک‌بارمصرف، Expiration)؛ تا Verify نشدن، **فقط** Purchase و Wallet Charge مسدود است (بقیه‌ی سایت آزاد؛ D-8 بسته شد) و کاربر به صفحه‌ی «تأیید Email» (با امکان ارسال مجدد لینک) هدایت می‌شود. Enforcement در Core است؛ Website فقط پیام/Redirect نشان می‌دهد (Master G11؛ Routeها: `verification.notice/verify/send` فقط Context اصلی؛ Gate: `EmailVerificationGate` داخل `PurchaseService::purchase` و `PaymentService::initiate`. تفسیر: Gate فقط برای User با Email تأییدنشده؛ User بدون Email — مثل Userهای ربات — مشمول نیست). Session: `Secure`، `HttpOnly`، Expiration، Revocation، مقاوم در برابر Fixation (Regenerate پس از Login). Password Reset بدون User Enumeration؛ Rate Limit طبق Matrix.

## 7. Telegram Linking
فقط برای User **احراز‌شده** (پشت `auth`). HMAC رسمی Telegram با `hash_equals`، `auth_date` ≤ ۲۴ ساعت، `state` یک‌بارمصرف Session-bound (ضد CSRF). Telegram متعلق به User دیگر ⇒ رد، بدون Merge. Audit: `identity.telegram_linked`, `identity.telegram_link_rejected_owned_by_other`.

## 8. Catalog، Pricing، Checkout
Product و قیمت فقط از Core؛ هیچ Price/User ID/Wallet ID/Store ID از Client Trusted نیست. سه قیمت: `main_price`, `reseller_price`, `customers_price`. Checkout مستقیم (بدون Cart): `Product → Checkout`. PurchaseGuard، Capacity، Order و Price Snapshot در Core. Reseller Product Creation خارج از Scope.

## 9. Payment (مطابق Master بخش ۷)
- Payment فقط `wallet_charge`؛ Website فقط UI: انتخاب مبلغ/روش، Redirect به Zarinpal، آپلود رسید Card-to-Card، نمایش وضعیت.
- Wallet Payment: `Wallet Debit → Purchase`. Direct Payment: `Payment → Confirmation → Wallet Credit`؛ سپس کاربر به Checkout برمی‌گردد و خرید از Wallet انجام می‌شود.
- States (از Core، بدون State Machine در Website): `pending / confirmed / rejected / refunded`.
- Card-to-Card: رسید ≤ 5MB، MIME allow-list، Storage خصوصی، سرو کنترل‌شده با `nosniff`.
- Payment ⟂ Purchase ⟂ Provisioning؛ `confirmed` به معنی «تکمیل خرید» نیست.
- Partial Wallet + Direct Payment خارج از Scope.

## 10. Wallet، Orders، Accounts
Wallet = User + StoreContext؛ Isolation کامل (Cross-Store و Cross-User ⇒ 403/404). Orders و Accounts فقط از CustomerAccount و Context جاری. Reseller فقط Store خودش.

## 11. Renewal / Referral / Commission / Discount / Provisioning / Refund / Retry
همه از Core. Website فقط درخواست و نمایش. Discount: حداکثر یکی در هر خرید (Master ۱۶)؛ Website فقط نتیجه را نمایش می‌دهد. پس از شارژ Wallet (Direct Payment) کاربر به Checkout برمی‌گردد و خرید خودکار ادامه نمی‌یابد (Master ۷.۲). Refund و Retry Provisioning **Admin-only** (همانند Bot)؛ Website نمایش وضعیت («نیازمند رسیدگی»، «بازگشت‌شده») دارد. Refresh/Double-Submit/Retry نباید Debit یا Purchase تکراری بسازد (Idempotency Token در هر Checkout).

## 12. Reseller Website
Branding، Menu اختصاصی (فقط برای Admin همان Store)، Management سبک (`/store/{slug}/manage/{customers,products,branding}`) بدون Business Rule در Controller. Reseller فقط Scope خود را می‌بیند.

## 13. Security Contract
HTTPS · Auth/Authorization در Backend (پنهان‌کردن UI Security نیست) · CSRF · Rate Limiting (Matrix جدا: `RATE-LIMIT-MATRIX.md`) · Input Validation · Output Encoding · Secure Upload · CSP (فعلاً `style-src 'unsafe-inline'` به‌عنوان بدهی شناخته‌شده) · Session Security · Audit در محل authoritative.

## 14. Observability
Correlation/Request ID برای هر Request حساس تا Core؛ Financial Truth فقط در Core.

## 15. تست‌ها (حداقل)
- **Guest**: ورود بدون Login · Email الزامی · Pending · Login/Register ادامه‌ی همان خرید · بدون User/CustomerAccount تکراری · Token Replay/Expiry/Cross-Store · تصادم با User موجود (بدون Merge).
- **Payment**: Valid/Invalid Transitions · Duplicate Webhook · Repeated Confirmation · Rejected · Refunded · Card-to-Card · Failure.
- **Wallet/Reseller/Renewal/Security**: طبق Master و Matrix.
- **Contract/Architecture Tests**: Website نباید مستقیماً `wallets.balance` را تغییر دهد یا `customers_price` را محاسبه کند.

## 16. Definition of Done

**Main Website**: Core Integration کامل · Guest Checkout (مدل جدید) · Pending → Login/Register → ادامه‌ی همان خرید · Telegram Linking · Login/Register · Wallet · Orders · Accounts · Wallet Charge (Zarinpal + Card-to-Card) · Renewal · Referral/Commission · **Production Verification**.

**Reseller Website**: StoreContext · Customer/Pricing/Wallet/Order/Payment Scope · Branding · Management · E2E.

## 17. Out of Scope
Cart · Multi-language · Multi-currency هم‌زمان (ارز قابل‌تنظیم نصب مجاز است) · Wallet Transfer · Partial Wallet + Direct Payment · Public API · Mobile App · Reseller Product Creation.

## 18. TBD Policy
TBD ≠ اجازه‌ی تصمیم در Channel. هر TBD: `Analyze → Check Master → Decide → Document → Implement → Test → Verify`.
**TBDهای بسته‌شده**: Guest Lifecycle/Data/Claiming/Telegram (Master §3) · Payment Purpose/States (Master §7) · Route Architecture · Store Identification · Session Config · Rate Limit Values · Password Reset (بخش‌های ۲۰–۲۴ پایین).
**TBDهای باقی‌مانده**: Frontend Framework/State/Accessibility/SEO · Monitoring/Error Tracking · Reseller Permissions دقیق.

## 19. Verification
هر Feature در `VERIFICATION-MATRIX.md` ثبت می‌شود (`SPECIFIED → IMPLEMENTED → TESTED → PRODUCTION VERIFIED` + `DEPRECATED`). `TESTED` = اجرای واقعی سبز.

---

## 20. Route Architecture (بسته شد — منطبق بر `routes/website.php`)

| Context | Prefix | نام Route | تشخیص Store |
|---|---|---|---|
| Main | `/` | `website.*` | Middleware `store.context` → Main StoreContext |
| Reseller | `/store/{slug}` | `website.store.*` | `store.context` با `{slug}` → Reseller StoreContext؛ پارامتر `{slug}` پس از ساخت Context از Route حذف می‌شود |

- **هر دو Context یک مجموعه Route/Controller مشترک** دارند (`registerSharedRoutes`)؛ Controllerها بین Main و Reseller تکرار نمی‌شوند.
- Reseller روی `/store/{slug}` است چون `/{slug}` متعلق به پنل Filament نماینده است؛ ورود پنل نماینده `/panel/login` و خروج Website `/sign-out` (نام `website.logout`).
- Reseller Management: `/store/{slug}/manage/{customers,products,branding}` فقط زیر `website.store.*` و پشت `auth`.
- همه‌ی Routeهای Website پشت `web`, `store.context`, `website.csp`. Cross-Store ⇒ 403/404.
- API Versioning: Website API عمومی ندارد (Out of Scope)؛ نسخه‌بندی لازم نیست.

## 21. Route Map (مرجع)

| گروه | مسیر | Middleware |
|---|---|---|
| عمومی | `/`، `/products/{product}` | — |
| Guest | `GET/POST /products/{product}/guest-checkout`، `GET /guest-checkout/pending` | `throttle:10,1` روی POST |
| Auth (guest-only) | `/register`، `/login`، `/forgot-password`، `/reset-password` | Login `5/min`؛ Forgot GET `3/hr`؛ Forgot POST IP `30/hr` + per-email `3/hr` |
| مشتری | `/products/{product}/checkout`، `/orders/{order}`، `/wallet`، `/wallet/charge`، `/wallet/charge/{payment}/receipt`، `/orders`، `/accounts`، `/accounts/{account}/renew`، `/referral`، `/profile`، `/identity/telegram/callback`، `/sign-out` | `auth` + `store.customer` |

Email Verification (فقط Context اصلی، پشت `auth`): `GET /email/verify` (notice)، `GET /email/verify/{id}/{hash}` (`signed` + `throttle:6,1`)، `POST /email/verification-notification` (`throttle:6,1`).

**حذف‌شده در فاز ۴ (DEPRECATED X4):** `POST /guest-checkout/purchase` و `/complete-profile` (تنظیم رمز برای User بدون رمز). `/profile` فقط اتصال Telegram است (G10).

## 22. Rate Limit Matrix (مقادیر واقعی Release 3.3.0)

| Endpoint | محدودیت | کلید |
|---|---|---|
| Login (GET/POST) | 5/min | IP (+ throttleKey Auth) |
| Forgot Password GET | 3/hr | IP |
| Forgot Password POST | 30/hr IP، و 3/hr per-email | IP و Email |
| Guest Checkout POST | 10/min | IP |
| Checkout POST | 10/min | IP |
| Wallet Charge POST | 10/min | IP |
| Receipt Upload POST | 5/min | IP |
| Telegram Link Callback | 20/min | IP |
| Email Verify / Resend | 6/min | IP |
| Register POST *(فاز ۸)* | 10/hr | IP |
| Zarinpal Callback GET *(فاز ۸)* | 30/min | IP |

هر تغییر مقدار باید همین جدول را به‌روز کند (Contract Test پیشنهادی در فاز ۳).

## 23. Session و Cookie (بسته شد)

- Driver پیش‌فرض کد و `.env.example` = `database` (هم‌راستا شد، فاز ۴؛ Production: `database` یا `redis`).
- `lifetime = 120` دقیقه؛ `http_only = true`؛ `same_site = lax`؛ `secure` از env؛ `.env.example` اکنون `SESSION_SECURE_COOKIE=true` دارد (Production الزامی).
- Guest Token Cookie: رمزنگاری‌شده (`EncryptCookies`)، ۴۵ دقیقه.
- Regenerate Session پس از Login.

## 24. Password Reset (بسته شد)
پاسخ همیشه یکسان (بدون User Enumeration)؛ محدودیت per-email 3/hr. Register باید Email را Verify کند (Master G11). Email واردشده در Guest Form هرگز به‌تنهایی Proof نیست (R9)؛ تطبیق با User موجود فقط به Login هدایت می‌کند.

## 25. Website Verification Matrix
مرجع: `VERIFICATION-MATRIX.md` (Contract 1.6). ردیف‌های Guest پس از فاز ۴ = Code ✅ / Test نوشته‌شده (اجرا نشده).

## 26. تصمیم‌ها و ریسک‌های پذیرفته‌شده‌ی Website

| کد | سؤال | پیشنهاد |
|---|---|---|
| D-6 | Google Sign-In | **بسته شد:** از Release اول حذف |
| D-7 | Email Verification در Register | **بسته شد:** بله (Master G11) |
| D-8 | کاربر Verify‌نشده فقط از Purchase و Wallet Charge منع شود؟ | **بسته:** بله |
| D-2 / D-3 | Discount / ادامه‌ی خودکار Purchase | **بسته** (Master ۱۶، ۷.۲) |
| D-4 | Retention | **بسته:** Guest ۶۰ روز؛ بقیه طبق `DATA-RETENTION.md` |
| D-5 | Money | **بسته و اجرا‌شده:** Integer Minor Unit، ارز قابل‌تنظیم (پیش‌فرض تومان) — Master ۴.۱ |

**هشدار Deploy (D-7):** با `MAIL_MAILER=log` هیچ Email تأییدی نمی‌رسد و خرید/شارژ ثبت‌نام‌های جدید مسدود می‌ماند؛ SMTP واقعی پیش‌نیاز Production است.

**کاهش ریسک (D-7):** چون Guest فقط با Email شروع می‌شود، بدون Verification هر کس می‌تواند Email دیگری را ثبت کند؛ Verify در Register و Gate روی Purchase/Charge این ریسک را می‌بندد.

## 25. Security Headers، Trusted Proxies و Callback درگاه (فاز ۸)

**هدرهای پایه** (`SecurityHeaders`؛ روی گروه `web` و هر دو پنل Filament؛ هدری که از قبل ست شده بازنویسی نمی‌شود):

| هدر | مقدار |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — فقط وقتی درخواست HTTPS است |
| `Content-Security-Policy` | فقط Website (بخش ۱۹)؛ اکنون با `object-src 'none'` |

**Trusted Proxies:** `TRUSTED_PROXIES` (پیش‌فرض `127.0.0.1,::1`). بدون آن IP همه‌ی کاربران پشت Nginx/cloudflared یکسان دیده می‌شود و تمام Rate Limitهای مبتنی بر IP مشترک می‌شوند. باید روی Staging با `request()->ip()` راستی‌آزمایی شود.

**Webhook تلگرام Fail-closed است:** Secret پیکربندی‌نشده (`TELEGRAM_WEBHOOK_SECRET` / `resellers.webhook_secret`) = رد (۴۰۳)، نه عبور؛ مقایسه با `hash_equals`.

**Callback درگاه:** `Authority` بازگشتی باید با `payments.gateway_reference` یکی باشد (`hash_equals`)؛ در غیر این صورت هیچ تغییر وضعیتی انجام نمی‌شود و پاسخ 404 عمومی است (`InvalidGatewayCallbackException`). `Status=NOK` فقط با Authority درست، پرداخت را Reject می‌کند.

**Renewal Website:** فرم تمدید `idempotency_token` می‌فرستد؛ کلید Core = `website-renew:{account}:{token}`.
