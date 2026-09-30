# Melorin — Master Architecture Contract

| فیلد | مقدار |
|---|---|
| **نسخه** | 2.7 |
| **وضعیت** | CANONICAL (تنها مرجع معتبر معماری) |
| **جایگزین** | Master v2.1 / v2.3 / 2.4 / 2.5 / 2.6 — DEPRECATED (v2.3: نام فایل 2.3، متن داخلی 2.1) |
| **تاریخ اعتبار** | ۱۴۰۵/۰۷/۰۸ (۲۰۲۶-۰۹-۳۰) |
| **زیرسند وابسته** | Website Architecture Contract v1.7 (`Parent Contract: Master 2.7`) |
| **مبنای Implementation** | Release 3.3.0 + فاز ۴ (Guest/Verify/Retention؛ متن Contract بدون تغییر) |
| **دامنه** | Core + Main/Reseller Store + Telegram Bots + Website + Admin + Wallet + Payment + Purchase + Provisioning + Renewal + Referral + Commission |

> این سند **یکپارچه‌شده (Consolidated)** است. هر Rule فقط یک‌بار و فقط یک‌جا تعریف شده است.
> تناقض‌های نسخه‌های قبل (به‌خصوص Guest، Payment Purpose، Payment States، Direct Payment) در این نسخه حل شده‌اند؛ فهرست کامل تغییرات در `PHASE-1-2-CHANGELOG.md`.

---

## 0. سلسله‌مراتب اسناد و چرخه‌ی وضعیت

```text
Master Architecture Contract (این سند)
        ↓
Website Architecture Contract
        ↓
Implementation (Code + Migrations)
        ↓
Tests
        ↓
History (PHASE-*, RELEASE-*)   ← فقط تاریخچه؛ منبع تصمیم نیست
```

- اگر کد با این سند مغایر باشد، **کد باید اصلاح شود** (یا این سند با تصمیم رسمی و افزایش نسخه اصلاح شود). کد به‌خودی‌خود Contract نیست.
- اسناد PHASE-* و یادداشت‌های Release تاریخچه‌اند و اگر با این سند تعارض داشتند، بی‌اعتبارند.

**وضعیت هر قابلیت** در `VERIFICATION-MATRIX.md` با یکی از این‌ها ثبت می‌شود:

```text
SPECIFIED → IMPLEMENTED → TESTED → PRODUCTION VERIFIED
                    و
                DEPRECATED   (تصمیم قدیمی که نباید دوباره پیاده شود)
```

`TESTED` یعنی تست **اجرا و سبز شده**، نه صرفاً نوشته شده. `DEPRECATED` در بخش ۲۰ ثبت می‌شود.

---

## 1. اصول معماری

1. Melorin یک **Modular Monolith با Core مرکزی** است.
2. همه‌ی Channelها (Main Bot، Reseller Bot، Main Website، Reseller Website، Admin، Jobs، Webhooks) فقط از Core استفاده می‌کنند و **Business Rule مستقل یا متناقض ندارند**.
3. لایه‌ها:

```text
Channel → Application Service / Facade → Domain Service → Model/Repository → DB
```

4. تمام قوانین مالی، Pricing، Scope، Purchase، Payment، Provisioning و Renewal در Core enforce می‌شوند. UI به‌تنهایی enforcement نیست.

---

## 2. هویت: User، StoreContext، CustomerAccount

| # | قاعده |
|---|---|
| R1 | Reseller = User موجود Main + Reseller Context. برای Reseller هرگز User جدید ساخته نمی‌شود. |
| R2 | `User ≠ CustomerAccount`. User هویت مرکزی است. |
| R3 | `CustomerAccount = User + StoreContext` (عضویت User در یک Store). |
| R4 | `StoreContext = {store_type: main|reseller, reseller_id}`؛ API حداقل: `isMain()`, `isReseller()`, `resellerId()`, `isOperational()`, `equals()`. |
| R5 | یک User می‌تواند هم‌زمان Customer Main و چند Reseller باشد. Unique مفهومی: `(user_id, StoreContext)`؛ **نه** `UNIQUE(user_id)`. |
| R6 | Customer بودن در Reseller A به معنی Customer بودن در Reseller B نیست؛ تغییر `reseller_id` نباید عضویت بسازد. Scope در Core enforce می‌شود. |
| R7 | **CustomerAccount فقط در لحظه‌ی خرید ساخته می‌شود** (Lazy). بازدید/GET صفحه هرگز CustomerAccount نمی‌سازد. (تصمیم صاحب پروژه، Release 3.2.17؛ اکنون Contract.) |
| R8 | CustomerAccountهای Storeهای مختلف بدون احراز و قاعده‌ی مشخص Merge نمی‌شوند. |
| R9 | Merge هویت‌ها فقط با اثبات معتبر مجاز است. `email`، `phone`، `telegram_id` خام، Cookie، IP و Session به‌تنهایی **Proof of Identity نیستند**. |

**Identity Resolution** (`IdentityService`): User را شناسایی می‌کند، StoreContext جاری را می‌گیرد، CustomerAccount موجود را Resolve یا (در صورت مجاز بودن و فقط در لحظه‌ی خرید) می‌سازد؛ تکراری نمی‌سازد؛ Idempotent است.

---

## 3. Guest — Contract نهایی (یکتا)

> این بخش **تنها** تعریف معتبر Guest است. مدل‌های «Guest بدون CustomerAccount خرید می‌کند»، «Provisional CustomerAccount» و «ساخت خودکار User از Guest» هر سه **DEPRECATED** هستند (بخش ۲۰).

### 3.1 تعریف

Guest یک **نشست موقت Website** برای *شروع* Checkout است، نه یک هویت و نه مجوز خرید. Guest بدون Login می‌تواند از صفحه‌ی Product «خرید به‌عنوان مهمان» را انتخاب کند، ولی **خرید نهایی فقط پس از Login/Register** انجام می‌شود.

### 3.2 جریان

```text
Product
  ↓
Buy as Guest
  ↓
Guest Form:  email (الزامی) · name (اختیاری) · phone (اختیاری)
  ↓
GuestCheckout record = pending   (token در Cookie رمزنگاری‌شده)
  ↓
Pending Page
  ↓
Login / Register
  ↓
ادامه‌ی همان خرید (همان Product، همان StoreContext)
  ↓
CustomerAccount Resolution (Lazy، در لحظه‌ی خرید)
  ↓
Checkout → Wallet Payment → Order → Provisioning
```

### 3.3 قواعد

| # | قاعده |
|---|---|
| G1 | شروع Guest Checkout نیازی به Login ندارد. |
| G2 | فیلدهای فرم: `email` (**تنها فیلد الزامی**، با Validation فرمت)، `name` و `phone` (**اختیاری**). (تصمیم صاحب پروژه، D-1 — بسته شد.) |
| G3 | Guest **هیچ User، هیچ CustomerAccount و هیچ Wallet/Order** نمی‌سازد. تنها رکورد مجاز: `guest_checkouts`. |
| G4 | Purchase نهایی بدون User احراز‌شده امکان‌پذیر نیست. |
| G5 | `GuestCheckout` وابسته به `product_id` و StoreContext (`reseller_id`) است و با Token تصادفی، Expiration و Cross-Store Validation محافظت می‌شود. نشست Main در Reseller قابل استفاده نیست و برعکس. |
| G6 | Login/Register باید بتواند **همان** خرید Pending را ادامه دهد؛ Login/Register مجدد، User/CustomerAccount/Purchase تکراری نمی‌سازد. |
| G7 | `email` (و در صورت وجود `name`/`phone`) واردشده در فرم Guest فقط برای پیش‌پر کردن فرم Register و ارتباط استفاده می‌شود. اگر با User موجود مطابقت داشت: **هرگز Merge یا Login خودکار**؛ کاربر به Login هدایت می‌شود و رویداد Audit می‌شود. |
| G8 | Guest Data محدود به نیاز Checkout است: `token, product_id, reseller_id, guest_email (NOT NULL), guest_name (nullable), guest_phone (nullable), status, expires_at`. IP/Device/Cookie/Session به‌عنوان هویت ذخیره یا استفاده نمی‌شوند. |
| G9 | چرخه‌ی عمر: `pending → consumed | expired`. TTL فعلی ۴۵ دقیقه (پارامتر Implementation). رکوردهای `expired` و `consumed` **۶۰ روز** پس از رسیدن به آن وضعیت **حذف فیزیکی** می‌شوند (D-4؛ جزئیات و Job در `DATA-RETENTION.md`). رکورد `pending` که `expires_at` آن گذشته، `expired` تلقی و مشمول همین ۶۰ روز است. |
| G11 | **Email Verification (D-7):** Register باید Email را Verify کند (لینک امضاشده، یک‌بارمصرف، با Expiration). تا Verify نشدن Email، **فقط** Purchase و Wallet Charge (شامل Direct Payment و Card-to-Card) مجاز نیست؛ بقیه‌ی سایت (مرور Product، Login، Profile، مشاهده‌ی Orders/Accounts/Wallet، Logout، ارسال مجدد لینک Verify) آزاد است (D-8 — بسته شد). Gate باید در **Core** (Application/Facade قبل از `PurchaseService` و `PaymentService::initiate`) enforce شود، نه فقط Middleware Website؛ Bot/Admin از این Gate مستثنا نیستند مگر User هویتش را از راه دیگری (Telegram Linking معتبر) ثابت کرده باشد — این استثنا فعلاً **تعریف نشده** و پیش‌فرض همان Gate است. Email واردشده در Guest Form هیچ‌گاه به‌تنهایی Verified تلقی نمی‌شود و همچنان Proof نیست (R9). |
| G10 | Telegram Linking فقط توسط User **احراز‌شده** برای اتصال Telegram خودش به User Canonical انجام می‌شود (نه اتصال Guest). ادعای Telegram ID بدون HMAC معتبر پذیرفته نمی‌شود؛ اگر Telegram قبلاً به User دیگر وصل است، Merge خودکار نمی‌شود. |

### 3.4 Guest در Reseller
همان جریان زیر `store/{slug}`؛ قیمت `customers_price`، Token با `reseller_id` همان Store، و Double Debit توسط `PurchaseService`.

---

## 4. Pricing

فقط سه مفهوم قیمت:

| قیمت | معنی | محل کسر |
|---|---|---|
| `main_price` | فروش مستقیم Main به Customer | Wallet کاربر / Main Context |
| `reseller_price` | تأمین Reseller از Main | Wallet **صاحب** Reseller / Main Context |
| `customers_price` | فروش Reseller به Customer خودش | Wallet Customer / Reseller Context |

- Product API: `mainPrice()`, `resellerPrice()`, `customersPrice($reseller)`.
- Reseller **Product جدید نمی‌سازد**؛ فقط `customers_price` و فعال/غیرفعال‌سازی Productهای Core.
- نام‌های Legacy **ممنوع** و بدون Alias: `base_price, core_price, sold_price, custom_price, corePrice, soldPrice, sellingPriceForReseller`. (`git grep` نباید نتیجه‌ای بدهد؛ Guard دائمی: `PricingNamingAndReportsTest`.)
- **خرید شخصیِ صاحب Reseller از Main**: Context = main ولی مبلغ = `reseller_price`.
- Discount فقط در Core محاسبه می‌شود؛ در هر Purchase **حداکثر یک** Discount اعمال می‌شود (بخش ۱۶).

---

## 4.1 Money Representation (D-5 — تصمیم اتخاذ‌شده، اجرا در فاز ۵)

| # | قاعده |
|---|---|
| M1 | همه‌ی مبالغ مالی در Core به‌صورت **Integer Minor Unit** نگهداری و محاسبه می‌شوند؛ `float` برای پول ممنوع است. |
| M2 | Signature سرویس‌های مالی (`WalletService`, `PaymentService`, `PurchaseService`, `RefundService`, `RenewalService`, Commission) `int` می‌گیرند/برمی‌گردانند؛ تبدیل به رشته‌ی نمایشی فقط در لایه‌ی UI. |
| M3 | ستون‌های مالی به `bigInteger` مهاجرت می‌کنند (Migration جداگانه، Backup اجباری، `IRREVERSIBLE`). |
| M4 | تا پایان فاز ۵ وضعیت فعلی (`decimal(15,2)` + ۸۱ مورد `(float)` در `app/`) **مغایر Contract و شناخته‌شده** است (Verification Matrix، C6). |
| M5 | Guard دائمی: تست Architecture که `(float)`/`float` را در مسیرهای مالی رد کند (مانند `PricingNamingAndReportsTest`). |

**D-5a (بسته):** واحد Minor Unit = **تومان** (عدد صحیح، بدون اعشار). پیش از Migration باید تأیید شود که مقادیر موجود `decimal(15,2)` اعشار غیرصفر ندارند؛ در غیر این‌صورت قاعده‌ی گرد کردن باید صریح تعریف شود.

## 5. Wallet

| # | قاعده |
|---|---|
| W1 | `Wallet = User + StoreContext`. نام فنی همیشه `wallet` (نه customer_wallet، reseller_wallet و …). |
| W2 | Walletهای Contextهای مختلف مستقل‌اند؛ Debit در Reseller A موجودی Main را تغییر نمی‌دهد. |
| W3 | Wallet مورد استفاده برای `reseller_price` = Wallet Main صاحب Reseller. Reseller Wallet مستقل ندارد. |
| W4 | Unique: یک Wallet برای هر `(user_id, store_type, reseller_id)`؛ رفتار NULL در MySQL برای `main` باید پوشش داده شود. |
| W5 | فقط `WalletService` موجودی را تغییر می‌دهد (`credit, debit, canDebit, adjust, getBalance`). ممنوع: `$wallet->balance -= x` بدون Ledger. |
| W6 | هر تغییر موجودی = یک `WalletTransaction` (`type, amount, balance_after, reference_type/id, description`) + قفل `lockForUpdate` داخل تراکنش. |
| W7 | Typeها: `charge, purchase, refund, commission, referral_bonus, admin_adjust, renewal, purchase_reversal`. |
| W8 | Admin Adjust = Wallet Update + Ledger + Audit. |
| W9 | نمایش/مصرف Wallet کاربر یا Context دیگر ممنوع (Isolation). |

---

## 6. Purchase (Wallet Purchase)

**Purchase همیشه از Wallet Context مربوطه Debit می‌شود.** هیچ Purchase مستقیماً Payment نمی‌سازد.

```text
PurchaseService
 Resolve StoreContext → Resolve/Create CustomerAccount (Lazy) → Validate Scope
 → Resolve Product → Resolve Pricing → PurchaseGuard
 → [BEGIN TX] Sale-Limit Reserve · Debit(s) · Create Order + Price Snapshot · Operation [COMMIT]
 → Provisioning (خارج از TX) → Referral/Commission → Notification
```

- **PurchaseGuard** (قبل از هر Debit): StoreContext، Customer Scope/Status، Product/Category Availability، Sale Limit، Account Limit، Customer Balance، Reseller Debt Limit، Server Eligibility/Capacity.
- **Double Debit (Reseller)**: `-customers_price` از Wallet Customer/Reseller و `-reseller_price` از Wallet Owner/Main، **در یک TX**؛ اگر یکی شکست خورد Rollback هر دو. این دو Debit ادغام نمی‌شوند. Profit = `customers_price − reseller_price` (فقط محاسبه، نه Debit).
- **Debt Limit**: موجودی منفی مجاز تا `debt_limit`؛ رسیدن به سقف فقط Purchase را Block می‌کند، نه کل Reseller را. Warning (`balance ≤ warning_threshold`) مستقل از Block است.
- **Sale Limit / Capacity**: Atomic Counter (`UPDATE` شرطی `units_sold < sale_limit`)؛ Capacity سرور با Reserve/Release Atomic.
- **Idempotency**: هر Purchase یک `Operation`/Identifier دارد؛ اجرای مجدد Debit/Order/Payment تکراری نمی‌سازد (`runOnce()`).
- **Price Snapshot**: Order قیمت لحظه‌ی خرید را نگه می‌دارد (Main: `main_price`؛ Reseller: `reseller_price` + `customers_price`). Refund فقط از Snapshot.

---

## 7. Payment — Contract نهایی

### 7.1 Purpose
- **Payment فقط یک Purpose دارد: `wallet_charge`.** Payment هرگز به Order وصل نمی‌شود و خرید هرگز Payment نمی‌سازد.
- `purpose = order` **DEPRECATED**: کد مرده بود و `PaymentService::initiate` آن را رد می‌کند. مقدار در enum دیتابیس فقط برای ردیف‌های تاریخی می‌ماند و برای Payment جدید ممنوع است.

### 7.2 Wallet Payment و Direct Payment

```text
Wallet Payment :  Wallet Debit → Purchase

Direct Payment :  Payment (Zarinpal / Card-to-Card)
                    → Confirmation → Wallet Credit (شارژ Context صحیح)
                    → کاربر به Checkout برمی‌گردد → Wallet Payment → Purchase
```

- Direct Payment یعنی **شارژ Wallet**، نه پرداخت مستقیم برای Order. ادامه‌ی خودکار Purchase پس از شارژ **ممنوع** است (D-3 — بسته شد): پس از Wallet Credit کاربر به صفحه‌ی Checkout همان Product/StoreContext بازمی‌گردد و باید Purchase را با یک Idempotency Token تازه **صراحتاً** تأیید کند. Callback/Webhook درگاه هرگز Purchase اجرا نمی‌کند.
- **Partial Wallet + Direct Payment** خارج از Scope (بخش ۱۹).
- «Direct Payment → Payment Confirmation → Purchase» (تعریف v2.3) **DEPRECATED**.

### 7.3 State Machine (Single Source of Truth)

وضعیت‌های واقعی = enum دیتابیس `payments.status`:

```text
pending ──► confirmed ──► refunded
   └──────► rejected
```

| از | به (مجاز) |
|---|---|
| pending | confirmed, rejected |
| confirmed | refunded |
| rejected, refunded | — (Terminal) |

- هر تغییر وضعیت **فقط** از `PaymentStateMachine::transition()` (از طریق `PaymentService`) انجام می‌شود؛ `update(['status'=>…])` مستقیم ممنوع. Transition نامعتبر → `InvalidPaymentTransitionException`.
- `finalize / reject / rejectByReseller / refund` با `lockForUpdate` داخل TX اجرا می‌شوند.
- `created`، `processing`، `partially_refunded` **پیاده نشده‌اند و در Contract فعلی وجود ندارند** (Payment مستقیماً `pending` ساخته می‌شود؛ Verify درگاه Synchronous است؛ Refund جزئی پشتیبانی نمی‌شود). اگر لازم شدند، با افزایش نسخه به‌عنوان Feature مستقل تعریف و Migration می‌شوند.

### 7.4 Card-to-Card
Upload رسید → `pending` → Review توسط Admin (یا Reseller برای شارژ Customer خودش) → `confirmed | rejected`. Confirmation Audit‌شده، ردیابی‌پذیر و Idempotent. Website/Telegram فقط UI‌اند. رسید: Storage خصوصی، Size ≤ 5MB، MIME allow-list، `nosniff`، نام فایل Client بی‌اعتماد.

### 7.5 Webhook
Idempotent؛ Webhook تکراری فقط یک‌بار Confirm می‌کند. Payment Confirmation خارج از Core منطق Business نمی‌سازد.

### 7.6 Payment ⟂ Purchase ⟂ Provisioning
سه State مستقل‌اند: `Payment = confirmed` لزوماً `Purchase completed` یا `Provisioning completed` نیست. Retry یک مرحله Debit/Purchase تکراری نمی‌سازد.

---

## 8. Provisioning

- `ProvisioningService` مستقل؛ به `VpnPanelDriver` (Sanaei, Marzban, PasarGuard, SoftEther) وابسته است، نه به یک Panel خاص.
- **خارج از TX مالی**: `TX{Order·Debit·Operation} → commit → Provisioning → Panel`.
- Lifecycle مستقل: `pending → provisioning → provisioned | provision_failed` (+ `failed`, `refunded` روی Order).
- هر تلاش واقعی یک `ProvisioningAttempt` (`operation_id, attempt_number, status, error, started_at, finished_at`).
- Retry: Idempotent، `claimForRetry()` اتمیک، **بدون Debit مجدد**؛ حداکثر ۳ تلاش عملیاتی، سپس Admin Notification.
- Failure Policy: `retry` یا `refund`.
- Server Selection فقط از Eligible Serverها (`LeastActiveAccountsStrategy`) با `selectAndReserve()`.

---

## 9. Renewal
`RenewalService` مستقل. Main: `main_price` از Main Wallet. Reseller: `customers_price` از Wallet Customer و `reseller_price` از Wallet Owner/Main. باید `expires_at` و Traffic را طبق Product Extend/Reset کند. Failure پنل → State/Operation مستقل (`renewal_pending/processing/success/failed`) با Retry/Compensation.

## 10. Refund
Main: `main_price`. Reseller: Customer ← `customers_price`، Owner ← `reseller_price`. همیشه از Order Price Snapshot، به Walletهای Context صحیح. Refund، Commission را **خودکار Reverse نمی‌کند**.

## 11. Referral / Commission / Referral Bonus
سه مفهوم مستقل. Referral = Attribution. Commission = پاداش مالی Snapshot‌شده (`commission_rate`, `commission_amount`) و برای هر Purchase واجد شرایط **یک** رکورد؛ Retry/Webhook تکراری Commission دوم نمی‌سازد. Referral Bonus مستقل از Commission است.

## 12. Account Lifecycle
Account متعلق به CustomerAccount و StoreContext است؛ Lifecycle: `active | expired | disabled`، مستقل از Status CustomerAccount. Suspend یک CustomerAccount بدون Rule صریح Accountهای قبلی را حذف/Disable نمی‌کند. Panel Usage با Account قابل Synchronize باشد.

## 13. Reseller Boundary و Admin
Reseller فقط داده‌ی خودش را می‌بیند/تغییر می‌دهد (Customers، Orders، CustomerAccounts، Pricing، Store Settings). Cross-Store → 403/404 طبق Contract. Main Admin طبق Permission؛ عملیات حساس Audit می‌شوند: Wallet Adjustment، Payment Confirmation، Refund، Reseller Modification، Product Price Change، Account Disable/Delete، Identity Linking.

---

## 14. Operation / Outbox / Jobs / Channels
- `Operation` Record برای Purchase, Provisioning, Renewal, Refund, Webhook (`operation_id, type, status, reference, attempts, error`).
- کارهای سنگین/Retryable = Job (`ProvisioningJob`, `ProvisioningRetryJob`, `RenewalJob`, `NotificationJob`, `BroadcastJob`, Webhook Processing). Job هرگز Debit مستقل از Core نمی‌سازد.
- Channel Boundary: Handler/Controller/Action → Application Service → Core.

## 15. Security و Logging
قبل از Production: `.env`، Secrets، Bot Tokens، Webhook/Payment Secrets، Admin Permissions، Customer/Reseller Boundaries. Secret افشا‌شده باید Rotate شود. Log نباید شامل password/bot token/webhook secret/payment secret باشد و باید برای Order/Payment/Operation/Provisioning/Retry/Failure قابل استفاده باشد.

## 16. Discount (D-2 — بسته شد)
Core-only؛ Website/Bot هرگز Discount محاسبه نمی‌کنند.

| # | قاعده |
|---|---|
| DS1 | **No Stacking:** در هر Purchase/Renewal حداکثر **یک** Discount اعمال می‌شود. اگر بیش از یک Discount واجد شرایط باشد، Core یکی را طبق قاعده‌ی تعیین‌شده در Implementation (پیشنهاد: بیشترین مبلغ تخفیف؛ تساوی → قدیمی‌ترین ایجاد) انتخاب می‌کند؛ ترتیب باید Deterministic و تست‌شده باشد. |
| DS2 | **Eligibility «اولین خرید»** از سابقه‌ی واقعی Purchase در Core محاسبه می‌شود، نه از Flag روی User و نه از ورودی Channel. واحد سنجش: `CustomerAccount` (یعنی هر Store جدا). Orderهای `failed` و `refunded` سابقه‌ی خرید حساب نمی‌شوند؛ `paid / provisioning / account_created / provision_failed` حساب می‌شوند (هم‌راستا با `ReferralService::isFirstPurchase`). |
| DS3 | Discount در Price Snapshot Order ثبت می‌شود (مبلغ قبل/بعد، شناسه‌ی Discount)؛ Refund از همان Snapshot. |
| DS4 | Discount روی `reseller_price` (تأمین Reseller) اثر نمی‌گذارد مگر Contract مستقل تعریف کند. |
| DS5 | **وضعیت Implementation: SPECIFIED فقط.** در Release 3.3.0 هیچ موتور Discount در کد وجود ندارد (`first_purchase_bonus` مربوط به Referral است، نه Discount). پیاده‌سازی = Feature جدید با Contract تکمیلی برای DS4 و Verification مستقل. |

---

## 17. Migration و Deployment (اصول)
- Migration تدریجی، دارای Backup و Validation؛ Backfill معنایی (نه Rename مکانیکی).
- Migration با Timestamp آینده قبل از Production بازبینی شود.
- Migrationهای Destructive/Irreversible (مثل Merge Wallet و حذف ستون‌های Legacy Wallet) با `down()` خالی **باید** به‌صورت `IRREVERSIBLE` علامت بخورند و Backup + Restore واقعی داشته باشند.
- Validation پس از Migration: Users، CustomerAccounts، Wallet Ownership/Balances، Orders، Payments، Accounts، Reseller Separation، Commission.
- ترتیب Deploy: Backup → Migration → Backfill → Validate → Enable Core → Financial Flow → Bot → Website → Reseller Website → Monitor. اگر مرحله‌ای مشکل داشت مرحله‌ی بعد فعال نمی‌شود.

## 18. Definition of Done

**Core**: User/CustomerAccount جدا و Store-scoped (Lazy) · Wallet = User+StoreContext · فقط سه قیمت · Purchase Atomic + Idempotent · Payment State Machine فعال · Price Snapshot · Debt Limit · Double Debit صحیح · Provisioning Lifecycle مستقل و Retry ≤ ۳ · Renewal مستقل · Referral/Commission جدا و Snapshot · Refund بدون Reverse خودکار Commission · **Guest طبق بخش ۳** · Telegram Linking معتبر.

**Reseller**: بدون User جدید · Context دارد · Main Customer باقی می‌ماند · Product نمی‌سازد · `customers_price` تعیین می‌کند · Wallet مشتریان Context-scoped · تأمین از Main Wallet Owner با `reseller_price` · Debt Limit · Scope enforce · Bot/Website از Core مشترک.

**Website**: به `WEBSITE-ARCHITECTURE-CONTRACT.md` بند «Definition of Done» ارجاع می‌شود.

**Production** (همه باید Verified باشند): Backup DB · Migrations · Data Backfill · Tests (کل پروژه) · Secrets Rotated · Webhook Secured · Audit · Payment/Purchase/Provisioning/Retry/Refund/Reseller Debt Tested · **Guest Checkout (مدل جدید) Tested** · Rollback Plan Verified · Independent Security Review · Backup Restore Test.

## 19. خارج از Scope فعلی
Advanced RBAC · Full Financial Reconciliation · Advanced Monitoring · Mobile App · Public API · Multi-language · Multi-currency · Object Storage · Wallet Transfer · **Partial Wallet + Direct Payment** · Reseller Product Creation · **Google Sign-In (D-6)** · Cart · Payment States `created/processing/partially_refunded` · ادامه‌ی خودکار Purchase پس از شارژ Direct Payment.
این موارد حذف دائمی نیستند؛ فعال‌سازی هرکدام = Contract مستقل + Verification.

---

## 20. رجیستر DEPRECATED

| # | مورد قدیمی | منبع قدیمی | جایگزین |
|---|---|---|---|
| X1 | Guest بدون Registration/بدون CustomerAccount خرید نهایی می‌کند | v2.3 بند ۱۶، ۱۷، ۱۲۳، ۱۴۶ و Website بند ۷، ۶۳، ۱۰۲، ۱۰۳ | بخش ۳ (G4) |
| X2 | Post-Purchase Identity Resolution برای Guest | همان | Login/Register **قبل** از خرید (G4، G6) |
| X3 | **Provisional/Unverified CustomerAccount** برای Guest | v2.3 بند ۶۲، ۱۲۶، Rule 26–27، ۱۴۰، ۱۴۵ | R7 + G3 |
| X4 | ساخت خودکار User + `Auth::login()` از Guest | `GuestPurchaseController` | G3، G7 |
| X5 | `purpose = order` | v2.3 بند ۵۱، ۵۲ | بخش ۷.۱ |
| X6 | «Direct Payment → Confirmation → Purchase» | v2.3 بند ۵۵، ۹۵؛ Website ۲۶ | بخش ۷.۲ |
| X7 | Payment مرتبط با Order (`order_id`) | v2.3 بند ۵۱ | Payment ⟂ Order |
| X8 | Payment States `created/processing/partially_refunded` | v2.3 بند ۵۳، ۱۲۴؛ Website ۶۷ | بخش ۷.۳ |
| X9 | `guest_checkouts.guest_email` اختیاری و `name`/`phone` الزامی | Migration + `GuestCheckoutController` + `GuestCheckoutService` | G2 (Email الزامی، بقیه اختیاری) |
| X10 | Legacy Pricing Names | v2.3 بند ۲۳ | بخش ۴ |
| X11 | Legacy Wallet columns `owner_type/owner_id/customer_account_id` | Phase 15 | `user_id + store_type + reseller_id` |

---

## 21. تصمیم‌ها (وضعیت)

| کد | موضوع | وضعیت |
|---|---|---|
| D-1 | phone در Guest | **بسته:** Email تنها فیلد الزامی (G2) |
| D-2 | Discount Stacking/Eligibility | **بسته:** No Stacking؛ اولین خرید از سابقه‌ی واقعی Core (بخش ۱۶) |
| D-3 | ادامه‌ی خودکار Purchase پس از شارژ | **بسته:** ممنوع؛ بازگشت به Checkout (بخش ۷.۲) |
| D-4 | Retention | **بسته:** Guest = ۶۰ روز؛ بقیه طبق `DATA-RETENTION.md` (تأییدشده توسط صاحب پروژه؛ تأیید حقوقی/حسابداری توصیه می‌شود) |
| D-5 | Money بدون float | **بسته:** Integer Minor Unit (بخش ۴.۱)؛ **D-5a بسته:** تومان |
| D-6 | Google Sign-In | **بسته:** حذف از Release اول |
| D-7 | Email Verification | **بسته:** بله (G11) |
| D-8 | Gate کاربر Verify‌نشده | **بسته:** فقط Purchase و Wallet Charge (G11) |

---|---|---|
| ~~D-1~~ | ~~الزامی‌بودن phone در Guest~~ | **بسته شد:** Email تنها فیلد الزامی؛ name و phone اختیاری (G2) |
| D-2 | Discount Stacking و Eligibility | تصمیم قبل از Implementation |
| D-3 | ادامه‌ی خودکار Purchase پس از شارژ Direct Payment | خارج از Scope؛ فعلاً کاربر به Checkout برمی‌گردد |
| D-4 | Retention رکوردهای Guest/Audit/Receipt | باید در `DATA-RETENTION.md` تعیین شود |
| ~~D-6~~ | ~~Google Sign-In~~ | **بسته شد:** حذف از Release اول |
| ~~D-7~~ | ~~Email Verification در Register~~ | **بسته شد:** بله، Register Email را Verify می‌کند (G11) |
| D-8 | کاربر Verify‌نشده فقط از Purchase و Wallet Charge منع شود (بقیه‌ی سایت آزاد)؟ | بله |
| D-5 | نمایش Money به‌صورت Integer Minor Unit | Phase 5 (Financial Hardening)؛ فعلاً `decimal(15,2)` |

---

## 22. قواعد نهایی (Rule Set)

1. Reseller = Existing Main User + Reseller Context.
2. `User ≠ CustomerAccount`؛ `CustomerAccount = User + StoreContext`؛ ساخت فقط در لحظه‌ی خرید.
3. `Wallet = User + StoreContext`.
4. Main Purchase → `main_price`؛ Reseller Customer Purchase → `customers_price`؛ Reseller Supply → `reseller_price` (Wallet Owner/Main).
5. `customers_price` هرگز از Wallet Owner Main کسر نمی‌شود؛ `main_price` در خرید Customer از Reseller استفاده نمی‌شود؛ `reseller_price` قیمت فروش به Customer نیست.
6. هر Debit/Credit Ledger دارد؛ Purchase Atomic و Idempotent است؛ Retry Provisioning Debit مجدد نمی‌سازد.
7. Refund از Snapshot؛ Commission با Refund خودکار Reverse نمی‌شود؛ Commission و Referral جدا هستند.
8. **Guest فقط Checkout را شروع می‌کند؛ Purchase پس از Login/Register؛ هیچ Provisional Account یا User خودکار.**
9. **Payment فقط `wallet_charge`؛ States: pending/confirmed/rejected/refunded؛ تغییر فقط از State Machine.**
10. **Direct Payment = شارژ Wallet؛ Purchase همیشه از Wallet.**
11. Merge هویت فقط با اثبات معتبر؛ Email/Phone/Telegram ID خام Proof نیستند.
12. Channelها Business Logic مستقل ندارند؛ Core مرجع نهایی است.
