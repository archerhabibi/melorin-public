> **Status: DEPRECATED — superseded by `docs/canonical/WEBSITE-ARCHITECTURE-CONTRACT.md` (Website 1.8).**
> (نام فایل 1.2؛ نسخه‌ی داخلی متن 1.1.) فقط تاریخچه.

# سند معماری و فنی Website — Melorin

**نسخه:** 1.1
**نوع سند:** زیرسند وابسته به سند جامع معماری و فنی Melorin
**مرجع بالادستی:** سند جامع معماری و فنی Melorin
**دامنه:** Main Website + Reseller Websites + Guest + Authentication + Checkout + Payment + Wallet + Orders + Renewal + Referral + Commission + Provisioning

---

## 1. جایگاه سند Website

این سند زیرسند معماری و فنی Website است.

در صورت هرگونه تعارض میان این سند و سند مادر:

```text
Master Architecture Contract
        ↓
Core Implementation Contract
        ↓
Website Subdocument
        ↓
Website Implementation
```

سند مادر اولویت دارد.

Website یک **Channel** است و مالک Business Logic مستقل نیست.

---

## 2. اصل بنیادین Website

Website مسئول:

- ارائه UI/UX
- دریافت Input
- نمایش Data
- مدیریت Session/Authentication در محدوده قرارداد
- فراخوانی Core
- نمایش Stateهای Core
- اجرای Flowهای تعریف‌شده توسط Core

است.

Website نباید:

- Pricing مستقل محاسبه کند.
- Wallet مستقل مدیریت کند.
- Purchase مستقل ایجاد کند.
- Payment State مستقل تعریف کند.
- Provisioning مستقل انجام دهد.
- قوانین Discount را مستقل محاسبه کند.
- قوانین Referral/Commission را مستقل محاسبه کند.
- Store Scope را دور بزند.

---

## 3. Main Website و Reseller Website

هر دو Website از Core مشترک استفاده می‌کنند.

```text
                 Core
              /        \
             /          \
     Main Website    Reseller Website
```

Main Website:

- فروش Main
- امکانات مربوط به Main
- مدیریت عمومی مجاز

Reseller Website:

- فروش همان Reseller
- Customerهای همان Reseller
- Productهای مجاز همان Reseller
- Pricing همان Reseller
- مدیریت اختصاصی همان Reseller

هر Reseller می‌تواند Website مستقل، URL مستقل، Branding مستقل و Menu مستقل داشته باشد.

---

## 4. StoreContext

تمام عملیات Website باید با StoreContext مشخص انجام شوند.

در Reseller Website:

```text
Current Website
      ↓
Reseller
      ↓
Reseller StoreContext
```

کاربر در یک Reseller Website فقط در Context همان Reseller فعالیت می‌کند؛ این به معنی آن نیست که User نمی‌تواند در Websiteهای Resellerهای دیگر نیز Customer باشد.

---

## 5. User و CustomerAccount

```text
User != CustomerAccount
```

و:

```text
User + StoreContext = CustomerAccount
```

Website نباید CustomerAccount را به‌عنوان User مستقل مدیریت کند.

---

## 6. Reseller Identity

Reseller:

```text
Existing Main User
+
Reseller Context
```

است.

ایجاد Reseller نباید User جدید ایجاد کند.

---

## 7. Guest

Guest یک هویت موقت Website است.

Guest:

- می‌تواند بدون Registration خرید کند.
- برای خرید الزاماً CustomerAccount ندارد.
- پس از خرید موفق، سیستم باید برای Identity Resolution تلاش کند.
- شکست Identity Resolution نباید خرید موفق را باطل کند.

---

## 8. Guest Checkout

Guest در Website می‌تواند از صفحه Product گزینه **«خرید به‌عنوان مهمان»** را انتخاب کند؛ بدون اینکه در ابتدای جریان Login کرده باشد.

فرم Guest:

```text
name
phone
email  ← required
```

- Email تنها فیلد الزامی است.
- با دریافت Email، یک `CustomerAccount` اولیه از نوع `Provisional / Unverified` برای StoreContext جاری ایجاد یا Resolve می‌شود.
- این CustomerAccount به معنی Trusted Identity نیست.
- Guest در این مدل Purchase نهایی را بدون Login/Register تکمیل نمی‌کند.

Flow:

```text
Guest
 ↓
Product
 ↓
Buy as Guest
 ↓
Guest Form
 ↓
Provisional / Unverified CustomerAccount
 ↓
Pending
 ↓
Login / Register
 ↓
Verified User
 ↓
CustomerAccount Resolution / Linking
 ↓
Checkout Completion
 ↓
Payment
 ↓
Order
 ↓
Provisioning
```

صفحه Pending باید:

- اطلاعات ثبت‌شده Guest را نمایش دهد.
- وضعیت Pending را مشخص کند.
- Login/Register را برای تکمیل همان خرید پیشنهاد/الزام کند.

---

## 9. Guest Identity Resolution

Email برای ایجاد/Resolve کردن CustomerAccount اولیه کافی است، اما برای Trusted Identity کافی نیست.

```text
Guest
 ↓
Email
 ↓
Provisional / Unverified CustomerAccount
 ↓
Pending
 ↓
Login / Register
 ↓
Verified User
 ↓
CustomerAccount Resolution / Linking
```

CustomerAccount تا زمان اتصال معتبر به User باید `Unverified / Unlinked` باقی بماند.

اگر Telegram برای Identity Linking استفاده شود:

```text
CustomerAccount
 ↓
Verified Telegram Linking
 ↓
Existing Core User
```

اتصال به User موجود باید با مکانیزم معتبر و احراز‌شده انجام شود.

Telegram ID خام، Email خام، IP، Cookie، Session یا Device Signals به‌تنهایی Proof of Identity نیستند.

هدف این مرحله این است که CustomerAccount اولیه به User Canonical صحیح متصل شود، بدون اینکه User یا CustomerAccount تکراری یا Merge اشتباه ایجاد شود.

---

## 10. Guest Data

Guest data باید محدود به نیازهای Checkout و ادامه همان جریان باشد.

نمونه:

```text
guest_name
guest_phone
guest_email
```

`email` الزامی است.

IP، Cookie، Session، Device Signals و Checkout Token به‌تنهایی Proof of Identity نیستند.

CustomerAccount اولیه باید وضعیت Identity خود را به‌صورت صریح نگهداری کند؛ حداقل مفهومی:

```text
Unverified / Unlinked
```

پس از Login/Register و Identity Linking معتبر، می‌تواند به وضعیت معتبر/متصل تبدیل شود.

---

## 11. Registration

```text
Pending
 ↓
Register
 ↓
Verified User
 ↓
CustomerAccount Resolution / Linking
 ↓
Checkout Completion
```

Registration نباید باعث ایجاد User یا CustomerAccount تکراری شود.

---

## 12. Authentication

روش‌های فعلی:

```text
Continue with Google
Email + Password
```

Login/Register باید بتواند جریان Pending Guest را ادامه دهد و همان CustomerAccount اولیه را به User معتبر متصل یا Resolve کند.

جزئیات UX و Provider Contract در Implementation تعیین می‌شود، مشروط بر اینکه با Security و Core Contract مغایرت نداشته باشد.


---

## 9. Guest Identity Resolution

پس از خرید موفق:

```text
Guest
 ↓
Identity Resolution
 ↓
Existing User / New User
 ↓
CustomerAccount
```

اتصال به User موجود باید با مکانیزم معتبر انجام شود.

Telegram ID واردشده توسط کاربر به‌تنهایی اثبات مالکیت Identity محسوب نمی‌شود.

برای اتصال امن می‌توان از مکانیزم‌هایی مانند authenticated linking/deep-link/token استفاده کرد.

---

## 10. Guest Data

Guest data باید محدود به نیازهای Checkout و Service Delivery باشد.

نمونه:

```text
guest_name
guest_phone
guest_email
```

IP، Cookie، Session، Device Signals و Checkout Token به‌تنهایی Proof of Identity نیستند.

---

## 11. Registration

```text
Guest
 ↓
Register
 ↓
User
 ↓
CustomerAccount
```

Registration نباید باعث ایجاد User یا CustomerAccount تکراری شود.

---

## 12. Authentication

روش‌های فعلی:

```text
Continue with Google
Email + Password
```

جزئیات UX و Provider Contract در Implementation تعیین می‌شود، مشروط بر اینکه با Security و Core Contract مغایرت نداشته باشد.

---

## 13. Session

Session باید:

- Secure
- HttpOnly در صورت استفاده از Cookie
- دارای Expiration
- قابل Revocation
- مقاوم در برابر Session Fixation

باشد.

جزئیات دقیق در بخش TBD قرار می‌گیرد.

---

## 14. Product Listing

Website Productها را از Core دریافت می‌کند.

Website نباید Product Price را از اطلاعات غیرقابل اعتماد Client قبول کند.

---

## 15. Product Detail

Product Detail باید اطلاعات مورد تأیید Core را نمایش دهد.

هر Price نمایش داده‌شده باید از Contract Pricing Core تبعیت کند.

---

## 16. Pricing

سه مفهوم فعلی:

```text
main_price
reseller_price
customers_price
```

Website نباید Price را مستقل محاسبه کند.

---

## 17. Reseller Product Scope

Reseller می‌تواند Productهای مجاز خود را انتخاب/فعال/غیرفعال کند، مشروط بر اینکه این قابلیت توسط Core پشتیبانی و enforce شود.

Reseller Product Creation فعلاً خارج از Scope است.

---

## 18. Checkout

Checkout مستقیم است:

```text
Product
 ↓
Checkout
```

Cart فعلاً وجود ندارد.

---

## 19. Cart

در Scope فعلی:

```text
No Cart
```

بنابراین:

```text
Product
 ↓
Checkout
```

Flow اصلی است.

---

## 20. Purchase Guard

Purchase Guard باید در Core اجرا شود.

Website فقط نتیجه را نمایش می‌دهد.

---

## 21. Capacity

Capacity توسط Core بررسی می‌شود.

اگر Capacity موجود نباشد:

```text
Core
 ↓
Capacity Error
 ↓
Website Error Display
```

Website نباید Capacity را مستقل تصمیم‌گیری کند.

---

## 22. Order

Order توسط Core ایجاد می‌شود.

Website فقط:

- Request
- Display
- Status Presentation

را انجام می‌دهد.

---

## 23. Price Snapshot

Order باید Price Snapshot داشته باشد.

Website نباید Price Snapshot را به‌صورت مستقل بسازد یا تغییر دهد.

---

## 24. Payment

Payment State و Payment Purpose توسط Core تعیین می‌شوند.

Website فقط UI مناسب Payment را ارائه می‌کند.

---

## 25. Wallet Payment

Wallet Payment:

```text
Wallet Payment
 ↓
Wallet Debit
 ↓
Purchase
```

---

## 26. Direct Payment

طبق سند مادر:

```text
Direct Payment
 ↓
Payment Gateway / Manual Payment
 ↓
Payment Confirmation
 ↓
Purchase
```

Partial Wallet + Direct Payment فعلاً خارج از Scope است.

---

## 27. Card-to-Card

در Card-to-Card فقط تصویر رسید Upload می‌شود.

```text
Upload Receipt
 ↓
Pending
 ↓
Admin Review
 ↓
Confirmed / Rejected
```

---

## 28. Payment State

Website State Machine مستقل ندارد.

State از Core دریافت می‌شود.

---

## 29. Payment و Purchase

Payment و Purchase مستقل هستند:

```text
Payment
 ↓
Purchase
 ↓
Provisioning
```

`Payment = Confirmed` لزوماً به معنی `Provisioning = Completed` نیست.

---

## 30. Wallet

Wallet:

```text
User + StoreContext
```

است.

Website فقط Wallet مربوط به Context مجاز را نمایش و از Core استفاده می‌کند.

---

## 31. Wallet Isolation

کاربر نباید بتواند Wallet مربوط به User دیگر یا StoreContext دیگر را مشاهده یا مصرف کند.

---

## 32. Orders

User فقط Orderهای مجاز خود و Context جاری را مشاهده می‌کند.

Reseller فقط Orderهای مجاز Store خود را مشاهده می‌کند.

---

## 33. Accounts

Accounts بر اساس CustomerAccount و Context Core نمایش داده می‌شوند.

---

## 34. Renewal

Renewal توسط Core تصمیم‌گیری می‌شود.

Website فقط:

- گزینه Renewal
- Price
- وضعیت
- نتیجه

را نمایش می‌دهد.

---

## 35. Referral

Referral از Core دریافت می‌شود.

Website نباید Referral Logic مستقل داشته باشد.

---

## 36. Commission

Commission توسط Core محاسبه و ثبت می‌شود.

Website فقط نتیجه را نمایش می‌دهد.

---

## 37. Discount

Discount فقط توسط Core محاسبه می‌شود.

Website نباید Discount مستقل محاسبه کند.

---

## 38. Discount Stacking

Rules مربوط به stacking توسط Core تعیین می‌شوند.

---

## 39. Provisioning

Website Provisioning انجام نمی‌دهد.

```text
Purchase
 ↓
Core Provisioning
 ↓
VPN Account
```

---

## 40. Provisioning Status

Website Statusهای Provisioning را از Core دریافت و نمایش می‌دهد.

---

## 41. Retry

Retry توسط Core کنترل می‌شود.

Website نباید با Refresh یا Retry باعث Debit یا Purchase تکراری شود.

---

## 42. Refund

Refund توسط Core انجام می‌شود.

Website فقط Request/Display را ارائه می‌کند.

Refund بر اساس Price Snapshot انجام می‌شود.

---

## 43. Reseller Financial Flow

برای Reseller Customer:

```text
customers_price
 ↓
Customer Wallet / Reseller Context
```

و:

```text
reseller_price
 ↓
Reseller Owner Wallet / Main Context
```

---

## 44. Main Financial Flow

```text
main_price
 ↓
Main Wallet
```

---

## 45. Reseller Profit

Profit:

```text
customers_price - reseller_price
```

و محاسبه نهایی در Core است.

---

## 46. Reseller Website Branding

هر Reseller Website می‌تواند:

- Name
- Logo
- Contact Information
- Branding

اختصاصی داشته باشد.

---

## 47. Reseller Menu

Menu Reseller باید با Main متفاوت باشد و عملیات مجاز Reseller را ارائه دهد.

---

## 48. Guest Menu

Guest Menu و CustomerAccount Menu باید متفاوت باشند.

---

## 49. Reseller Management

Reseller می‌تواند طبق مجوز Core:

- Customerهای خود را مشاهده کند.
- Walletهای Customerهای خود را مشاهده کند.
- Productهای مجاز را فعال/غیرفعال کند.
- Pricingهای مجاز را مدیریت کند.

---

## 50. Authorization

Authorization باید در Core/Backend enforce شود.

UI hiding به‌تنهایی Security محسوب نمی‌شود.

---

## 51. Store Boundary

هر Request باید Context معتبر داشته باشد.

---

## 52. Input Trust

هیچ Price، User ID، Wallet ID، Store ID یا Permission ارسالی از Client نباید Trusted فرض شود.

---

## 53. Idempotency

Checkout، Payment، Purchase و عملیات حساس باید Idempotency مناسب داشته باشند.

---

## 54. Double Submission

Double Click، Refresh یا Retry نباید Purchase یا Debit تکراری ایجاد کند.

---

## 55. Webhook

Webhook توسط Backend/Core پردازش می‌شود.

Website نباید Webhook را منبع نهایی Financial Truth فرض کند.

---

## 56. Error Handling

Core Errorها باید به Error Contract قابل نمایش در Website تبدیل شوند.

Website نباید Business Error را با منطق مستقل تفسیر و تغییر دهد.

---

## 57. Logging

Website باید Logهای فنی مناسب تولید کند، اما Financial Truth در Core ثبت می‌شود.

---

## 58. Audit

عملیات حساس مانند:

- Payment Confirmation
- Refund
- Wallet Adjustment
- Identity Linking

باید Audit قابل ردیابی داشته باشند.

---

## 59. Security

حداقل:

- HTTPS
- Secure Authentication
- Authorization
- CSRF Protection در صورت Cookie-based Auth
- Rate Limiting
- Input Validation
- Output Encoding
- Secure File Upload
- Session Security

باید رعایت شود.

---

## 60. Receipt Upload Security

Receipt Image باید:

- محدودیت Size داشته باشد.
- MIME/Content بررسی شود.
- نام فایل از Client Trusted نباشد.
- مسیر Storage امن باشد.
- در صورت نیاز Malware/Content validation داشته باشد.

---

## 61. Main Website E2E

حداقل:

```text
Login
 ↓
Product
 ↓
Checkout
 ↓
Payment
 ↓
Purchase
 ↓
Provisioning
```

باید تست شود.

---

## 62. Reseller Website E2E

```text
Reseller Context
 ↓
Customer
 ↓
Product
 ↓
customers_price
 ↓
Customer Wallet
 ↓
Order
 ↓
Provisioning
```

هم‌زمان Financial Flow مربوط به Owner نیز باید طبق Core Contract Verification شود.

---

## 63. Guest E2E

```text
Guest
 ↓
Product
 ↓
Checkout
 ↓
Payment
 ↓
Order
 ↓
Provisioning
```

بدون Registration باید امکان‌پذیر باشد.

---

## 64. Guest / Pending Checkout E2E

### Guest Entry

```text
Product
 ↓
Buy as Guest
 ↓
Guest Form
```

باید Login در ابتدای جریان الزامی نباشد.

### Guest Form

```text
name
phone
email ← required
```

باید Email الزامی باشد و Validation صحیح انجام شود.

### Provisional CustomerAccount

```text
Guest
 ↓
Email
 ↓
Provisional / Unverified CustomerAccount
```

باید CustomerAccount اولیه ایجاد/Resolve شود و Trusted Identity محسوب نشود.

### Pending

```text
Provisional CustomerAccount
 ↓
Pending
 ↓
Login / Register
```

باید اطلاعات Guest نمایش داده شود و Login/Register برای تکمیل همان خرید پیشنهاد/الزام شود.

### Completion

```text
Login / Register
 ↓
Verified User
 ↓
CustomerAccount Resolution / Linking
 ↓
Checkout Completion
 ↓
Payment
 ↓
Order
 ↓
Provisioning
```

Purchase نهایی نباید قبل از Login/Register تکمیل شود.

### Telegram Linking

باید تست شود که:

- Telegram Linking احراز‌شده باشد.
- Telegram ID خام به‌تنهایی Proof نباشد.
- CustomerAccount صحیح به User Canonical متصل شود.
- Merge اشتباه رخ ندهد.

### Duplicate / Failure

باید تست شود:

- Retry باعث Duplicate CustomerAccount یا Purchase نشود.
- Login/Register دوباره User تکراری نسازد.
- CustomerAccount بین Storeها اشتباه Merge نشود.
- CustomerAccount Unverified به‌اشتباه Trusted تلقی نشود.


---

## 65. Website Wallet Tests

باید تست شود:

- موجودی صحیح
- Context صحیح
- عدم دسترسی Cross-Store
- عدم دسترسی به Wallet دیگران
- Debit Result
- Credit Result
- Ledger Reference

---

## 66. Website Reseller Tests

باید تست شود:

- Reseller Scope
- Customer Scope
- Product Scope
- Pricing
- Wallet Scope
- Order Scope
- Permission Boundary

---

## 67. Payment Tests

حداقل:

```text
Created
Pending
Processing
Confirmed
Rejected
Refunded
```

و:

- Duplicate Webhook
- Repeated Confirmation
- Failed Payment
- Manual Payment
- Card-to-Card

---

## 68. Renewal Tests

Renewal باید با Core انجام شود و:

- Time
- Traffic
- Price
- Wallet
- Provisioning

را درست نمایش و اجرا کند.

---

## 69. Security Tests

حداقل:

- Authentication
- Authorization
- Session
- CSRF
- XSS
- Injection
- IDOR
- Rate Limiting
- File Upload

---

## 70. Main Website Definition of Done

Main Website زمانی Done است که:

- Core Integration کامل باشد.
- Guest Checkout تست شده باشد.
- Guest Form با Email الزامی تست شده باشد.
- Pending Page تست شده باشد.
- Login/Register Completion از Pending تست شده باشد.
- Telegram Identity Linking تست شده باشد.
- Login/Register تست شده باشد.
- CustomerAccount تست شده باشد.
- Wallet تست شده باشد.
- Orders تست شده باشد.
- Payment تست شده باشد.
- Renewal تست شده باشد.
- Referral/Commission تست شده باشد.
- Manual Payment تست شده باشد.
- Production Verification انجام شده باشد.

---

## 71. Reseller Website Definition of Done

Reseller Website زمانی Done است که:

- StoreContext صحیح باشد.
- Customer Scope صحیح باشد.
- Pricing صحیح باشد.
- Wallet Scope صحیح باشد.
- Order Scope صحیح باشد.
- Payment Scope صحیح باشد.
- Branding صحیح باشد.
- Management Panel صحیح باشد.
- E2E تست شده باشد.

---

## 72. Verification Levels

هر Feature Website باید یکی از وضعیت‌های زیر را داشته باشد:

```text
SPECIFIED
IMPLEMENTED
TESTED
PRODUCTION VERIFIED
```

این وضعیت‌ها در Verification Matrix ثبت می‌شوند.

---

## 73. Production Checklist

قبل از Production:

- Migration Verified
- Backup Verified
- Payment Tested
- Purchase Tested
- Provisioning Tested
- Retry Tested
- Refund Tested
- Guest Checkout Tested
- Security Tested
- Audit Enabled
- Rollback Plan Verified

---

## 74. Deployment Order

ترتیب:

```text
Core
 ↓
Financial Flow
 ↓
Telegram Integrations
 ↓
Main Website
 ↓
Reseller Websites
 ↓
Monitoring
```

اگر یک مرحله مشکل داشته باشد، مرحله بعدی فعال نمی‌شود.

---

## 75. Out of Scope

فعلاً:

- Cart
- Multi-language
- Multi-currency
- Wallet Transfer
- Partial Wallet + Direct Payment
- Public API
- Mobile App
- Reseller Product Creation

خارج از Scope هستند.

---

## 76. Future Features

در آینده می‌توان:

- English
- Currency Expansion
- Cart
- Advanced RBAC
- Public API
- Mobile App
- Advanced Monitoring

را به‌عنوان Feature مستقل تعریف کرد.

---

## 77. TBD Policy

TBD به معنی «تصمیم نگرفته‌شده» است و **به هیچ عنوان به معنی اجازه برای تصمیم‌گیری خودسرانه در Channel نیست.**

تا زمانی که یک TBD به‌صورت رسمی تصمیم‌گیری و به Contract تبدیل نشده است:

1. Implementation باید با Core موجود هماهنگ باشد.
2. Implementation نباید Business Logic جدید و مستقل در Website ایجاد کند.
3. در صورت وجود چند انتخاب فنی، تصمیم باید بر اساس Architecture موجود، Security، Consistency و کمترین تغییر ناسازگار گرفته شود.
4. Website نباید برای حل یک TBD، Contract مستقلی ایجاد کند.
5. تصمیم نهایی Implementation در موارد TBD با برنامه‌نویس/معمار پروژه است، مشروط به عدم تعارض با سند مادر.
6. هر تصمیم مهم گرفته‌شده باید از TBD خارج و در مستندات ثبت شود.

---

# 78. TBD Register

موارد زیر در زمان تهیه این سند نیازمند تصمیم یا Verification دقیق هستند:

### Architecture / Routing

- URL/Route Architecture
- Main vs Reseller route strategy
- Tenant/Store identification
- API versioning

### Authentication

- Session architecture
- Token vs Cookie
- Refresh strategy
- Password reset
- Email verification
- Google identity linking

### Guest

- Guest Session lifecycle
- Guest Checkout lifecycle
- Guest CustomerAccount provisional/unverified lifecycle
- Pending Page behavior
- Guest data retention
- Guest claiming/linking flow
- Guest-to-Telegram verified linking mechanism

### Reseller

- Reseller identification mechanism
- Branding contract
- Reseller management permissions
- Exact product visibility rules
- Exact customer management permissions

### Payment

- Payment UI
- Manual Payment UI
- Receipt storage implementation
- Payment error mapping
- Webhook integration details

### Orders / Provisioning

- Order status presentation
- Provisioning status presentation
- Retry UI
- Failure UI
- Refund UI

### Security

- Cookie configuration
- CSRF strategy
- Rate Limiting values
- CSP
- File upload limits
- Session expiration
- Device/session management

### Frontend

- Frontend framework
- Component architecture
- State management
- Form validation
- Accessibility
- SEO
- Responsive/mobile strategy

### Operations

- Logging format
- Correlation ID
- Monitoring
- Error tracking
- Deployment environment
- Staging/Production configuration

---

# 79. TBD Decision Rule

برای هر TBD:

```text
TBD
 ↓
Technical Analysis
 ↓
Core Compatibility Check
 ↓
Security Check
 ↓
Architecture Check
 ↓
Decision
 ↓
Document Update
 ↓
Implementation
 ↓
Test
 ↓
Production Verification
```

قبل از مرحله `Decision`، Channel نباید Contract مستقل ایجاد کند.

---

# 80. Currency

Currency فعلاً خارج از Scope نهایی Website است.

هرگونه UI مربوط به Currency در آینده باید ابتدا با Currency Contract Core هماهنگ شود.

Website نباید در حال حاضر Wallet یا Price را به‌صورت مستقل چندارزی کند.

---

# 81. Language

Language فعلاً خارج از Scope نهایی است.

نسخه فعلی Website می‌تواند فارسی باشد.

فعال‌سازی English یا Multi-language نیازمند Contract مستقل خواهد بود.

---

# 82. Timezone

UI می‌تواند Local Time نمایش دهد.

Backend/Core منبع اصلی Timestamp و Time Contract باقی می‌ماند.

---

# 83. Mobile Profile

در Profile فعلاً موارد زیر در نظر گرفته می‌شوند:

```text
Password
Avatar
Language
Timezone
```

Language و Timezone تا زمان فعال‌شدن Contract کامل باید با TBD Policy هماهنگ باشند.

---

# 84. Main Management

Main Website علاوه بر فروش، می‌تواند Management Panel مربوط به Main را ارائه کند؛ اما Authorization و Business Logic باید از Core بیاید.

---

# 85. Reseller Management

Reseller Website می‌تواند Management Panel اختصاصی Reseller داشته باشد.

Reseller فقط Scope خود را می‌بیند.

---

# 86. Cross-Store Visibility

User می‌تواند Customer چند Store باشد، اما در هر Website فقط Context مجاز همان Website فعال است.

---

# 87. Identity Merge

Merge فقط در صورت اثبات معتبر Identity مجاز است.

Website نباید صرفاً بر اساس:

```text
email
phone
telegram_id
cookie
IP
```

بدون Contract معتبر، دو Identity را Merge کند.

---

# 88. Data Consistency

Website Data Cache نباید منبع نهایی Financial یا Identity Truth باشد.

Core منبع نهایی است.

---

# 89. API Contract

Website API Contract باید:

- Typed
- Versioned where needed
- Authenticated
- Authorized
- Idempotent where needed

باشد.

---

# 90. Client-Side Rules

Client-side validation برای UX مجاز است، اما Security/Business Rule محسوب نمی‌شود.

هر Rule مهم باید Server/Core-side enforce شود.

---

# 91. Error Consistency

خطاهای مشابه Core/Telegram/Website باید تا حد ممکن Contract مشترک داشته باشند.

---

# 92. Telegram Coordination

Website و Telegram باید برای:

- User
- CustomerAccount
- StoreContext
- Pricing
- Wallet
- Payment
- Purchase
- Order
- Renewal
- Referral
- Commission

از Core مشترک استفاده کنند.

---

# 93. Website as Channel

Website:

```text
Input
 ↓
Core
 ↓
Result
 ↓
UI
```

است.

نه:

```text
Input
 ↓
Website Business Logic
 ↓
Database
```

مستقل از Core.

---

# 94. Database Access

در صورت معماری Service-based، Website نباید مستقیماً قواعد Domain را با Queryهای مستقل دور بزند.

---

# 95. Caching

Caching نباید باعث نمایش Financial/Identity State قدیمی به‌عنوان حقیقت نهایی شود.

---

# 96. Observability

هر Request حساس باید در صورت امکان Correlation/Request ID داشته باشد تا از Website تا Core قابل ردیابی باشد.

---

# 97. Audit Boundary

Audit عملیات حساس باید در محل authoritative انجام شود؛ صرفاً ثبت UI Event کافی نیست.

---

# 98. Deployment Environment

Website باید ابتدا در Staging با Core واقعی/معادل Contract تست شود و سپس به Production منتقل شود.

---

# 99. Production Verification

Production Verified فقط زمانی ثبت می‌شود که Flow واقعی در محیط Production طبق معیارهای Definition of Done Verification شده باشد.

---

# 100. Canonical Contract Relationship

این سند Website Contract را برای Channel تعریف می‌کند.

در موارد اختلاف:

```text
Master
  >
Core Contract
  >
Website Contract
```

Website Contract نباید Rule بالادستی را تغییر دهد.

---

# 101. موارد TBD و قاعده تصمیم‌گیری

موارد TBD در این سند به معنی وجود یک جای خالی برای تصمیم فنی هستند، نه مجوز برای تصمیم‌گیری خودسرانه در Channel.

**اصل مهم:**

> TBD به معنی اجازه برای تصمیم‌گیری خودسرانه در Channel نیست.

تا زمان تصمیم نهایی:

- Implementation باید با Core و Architecture موجود هماهنگ باشد.
- Website نباید Business Logic جدید ایجاد کند.
- Website نباید Contractی تعریف کند که در Core وجود ندارد.
- اگر چند انتخاب فنی وجود داشته باشد، برنامه‌نویس/معمار پروژه باید گزینه سازگار با Core، Architecture، Security و Maintainability را انتخاب کند.
- تصمیم اتخاذشده باید در سند ثبت شود.
- پس از ثبت تصمیم، TBD باید به Contract مشخص تبدیل شود.
- هر TBD مهم باید در Verification Matrix وضعیت داشته باشد.

موارد TBD می‌توانند شامل موارد زیر باشند:

```text
Route Architecture
Authentication UX
Session Strategy
Guest Session Lifecycle
Guest Claiming
Guest ↔ Telegram Linking
Reseller Identification
Branding Contract
Product API Contract
Checkout API
Payment UI
Manual Payment UI
Receipt Storage
Order Status UI
Provisioning Status UI
Error Mapping
Cookie Policy
CSRF Strategy
Rate Limiting
CSP
Frontend Framework
State Management
Accessibility
SEO
API Versioning
Monitoring
Error Tracking
```

### Rule نهایی TBD

```text
TBD
 ↓
Analyze
 ↓
Check Master/Core
 ↓
Choose compatible implementation
 ↓
Document Decision
 ↓
Implement
 ↓
Test
 ↓
Production Verify
```

تا قبل از ثبت Decision، هیچ Channel حق ندارد TBD را به یک Business Rule مستقل تبدیل کند.

---

# 102. Website Final Architecture

```text
                    Melorin Core
                         │
          ┌──────────────┴──────────────┐
          │                             │
    Main Website                  Reseller Website
          │                             │
      Main Context                 Reseller Context
          │                             │
      Main Customer              Reseller Customer
          │                             │
       Main Wallet                Customer Wallet
          │                             │
       Main Order                  Reseller Order
          └──────────────┬──────────────┘
                         │
                    Provisioning
                         │
                     VPN Account
```

Guest:

```text
Guest
 ↓
Website
 ↓
Checkout
 ↓
Payment
 ↓
Order
 ↓
Provisioning
 ↓
Identity Resolution
 ↓
User / CustomerAccount
```

---

# 103. Website Final Rules

1. Website Channel است.
2. Core Business Logic را مالک است.
3. Guest بدون CustomerAccount می‌تواند خرید کند.
4. Guest پس از Purchase موفق برای Identity Resolution بررسی می‌شود.
5. Reseller User جدید نیست.
6. CustomerAccount = User + StoreContext.
7. Wallet = User + StoreContext.
8. Pricing از Core می‌آید.
9. Payment از Core می‌آید.
10. Purchase از Core می‌آید.
11. Provisioning از Core می‌آید.
12. Renewal از Core می‌آید.
13. Referral/Commission از Core می‌آیند.
14. Cross-Store access ممنوع است مگر در Context مجاز.
15. TBD مجوز Business Logic مستقل نیست.
16. هر تصمیم TBD باید مستند شود.
17. Website و Telegram باید با Core Contract مشترک هماهنگ باشند.

---

# 104. Website Verification Matrix

هر قابلیت باید با این چهار وضعیت ثبت شود:

| Feature | SPECIFIED | IMPLEMENTED | TESTED | PRODUCTION VERIFIED |
|---|---|---|---|---|
| Guest Checkout | ☐ | ☐ | ☐ | ☐ |
| Guest Form / Email | ☐ | ☐ | ☐ | ☐ |
| Pending / Login-Register Completion | ☐ | ☐ | ☐ | ☐ |
| Telegram Identity Linking | ☐ | ☐ | ☐ | ☐ |
| Guest Identity Resolution / CustomerAccount Linking | ☐ | ☐ | ☐ | ☐ |
| Login | ☐ | ☐ | ☐ | ☐ |
| Register | ☐ | ☐ | ☐ | ☐ |
| CustomerAccount | ☐ | ☐ | ☐ | ☐ |
| Wallet | ☐ | ☐ | ☐ | ☐ |
| Main Purchase | ☐ | ☐ | ☐ | ☐ |
| Reseller Purchase | ☐ | ☐ | ☐ | ☐ |
| Payment | ☐ | ☐ | ☐ | ☐ |
| Card-to-Card | ☐ | ☐ | ☐ | ☐ |
| Orders | ☐ | ☐ | ☐ | ☐ |
| Provisioning | ☐ | ☐ | ☐ | ☐ |
| Renewal | ☐ | ☐ | ☐ | ☐ |
| Referral | ☐ | ☐ | ☐ | ☐ |
| Commission | ☐ | ☐ | ☐ | ☐ |
| Refund | ☐ | ☐ | ☐ | ☐ |
| Reseller Management | ☐ | ☐ | ☐ | ☐ |
| Security | ☐ | ☐ | ☐ | ☐ |
| Production Verification | ☐ | ☐ | ☐ | ☐ |
