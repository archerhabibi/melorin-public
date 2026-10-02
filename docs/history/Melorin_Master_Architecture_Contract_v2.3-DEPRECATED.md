> **Status: DEPRECATED — superseded by `docs/canonical/MASTER-ARCHITECTURE-CONTRACT.md` (Master 2.8).**
> (نام فایل 2.3؛ نسخه‌ی داخلی متن 2.1.) فقط تاریخچه.

**سند
جامع معماری و فنی Melorin**

**Pricing، Wallet، Reseller، CustomerAccount، Payment، Purchase، Provisioning و Renewal**

**نسخه
سند:** 2.1

**وضعیت:**
مرجع اصلی
معماری و پیاده‌سازی

**مبنای سند:**
تلفیق سند جامع
معماری و فنی نسخه 2.0 با اسناد معماری و پیاده‌سازی موجود در پروژه
Melorin

**دامنه:**
Core + Main Store + Reseller Store + Telegram Bots + Website + Admin Panel +
Wallet + Payment + Purchase + Provisioning + Renewal + Referral + Commission

---

**1. اولویت و سلسله‌مراتب اسناد**

این
سند مرجع اصلی معماری Melorin است.

قاعده
اولویت:

این
سند

    ↓

کد و
Implementation فعلی

    ↓

Migrationهای
جدید

    ↓

Testها

    ↓

مستندات
قدیمی

اسناد
قبلی پروژه که داخل فایل ZIP قرار دارند، منبع اطلاعات تکمیلی هستند، اما اگر
با این سند تعارض داشته باشند، **این سند اولویت دارد**.

به‌خصوص
در موارد زیر، این سند تصمیم نهایی را مشخص می‌کند:

Pricing

Wallet

StoreContext

Reseller Financial Flow

CustomerAccount

Order

Payment

Purchase

Refund

Provisioning

Renewal

**پیشنهاد:**

یک سند
را به‌عنوان **Canonical Architecture Contract** تعیین کنید و وضعیت هر قابلیت را
مشخص کنید:

SPECIFIED

IMPLEMENTED

TESTED

PRODUCTION VERIFIED

هدف
این است که مشخص باشد هر قابلیت فقط در سند تعریف شده، در کد پیاده‌سازی شده، تست
شده یا واقعاً در محیط Production نیز Verification شده است.

---

**2. اصل بنیادین معماری**

Melorin یک **Modular
Monolith با
Core مرکزی** است.

ساختار
مفهومی:

                        
MELORIN

                           
│

                   
┌───────┴───────┐

                   
│   MELORIN CORE │

                   
│                │

                    │ Identity       │

                    │
Customer       │

                    │
StoreContext    │

                    │
Product        │

                    │
Pricing        │

                    │
Wallet         │

                    │
Payment        │

                    │
Order          │

                    │
Purchase       │

                    │
Provisioning   │

                    │
Renewal        │

                    │
Referral       │

                    │
Commission     │

                    │ Reseller       │

                   
└───────┬────────┘

                           
│

         
┌─────────────────┼─────────────────┐

          │                 │                 │

          ▼                 ▼                 ▼

      Main Bot         Reseller Bot       Website

          │                 │                 │

         
└─────────────────┬─────────────────┘

                           
│

                      
Admin Panel

همه
Channelها
باید از Core استفاده
کنند.

---

**3. معماری Layerها**

Business Logic نباید داخل Channelها پراکنده شود.

مدل:

Channel

   ↓

Application Service

   ↓

Domain Service

   ↓

Repository / Model

   ↓

Database

Channelهای
سیستم:

Main Telegram Bot

Reseller Telegram Bot

Main Website

Reseller Website

Admin Panel

Jobs

Webhooks

هیچ‌کدام
نباید Business Rule مستقل
و متناقض داشته باشند.

---

**4. اصل Multi-Store**

Melorin چند
Store دارد:

Main Store

Reseller A

Reseller B

Reseller C

...

اما
Identity مرکزی
است.

یعنی:

User

موجودیت
هویتی مرکزی است و Customer بودن یک User در
Store توسط:

CustomerAccount

مشخص
می‌شود.

---

**5. User و CustomerAccount**

این دو
مفهوم نباید یکی در نظر گرفته شوند.

User

\=

Identity

و:

CustomerAccount

\=

User membership in Store

بنابراین:

User Ali

│

├── CustomerAccount / Main

├── CustomerAccount / Reseller A

└── CustomerAccount / Reseller C

---

**6. Reseller User**

Reseller User مستقل نیست.

Reseller قبلاً
User و
Customer در
Main بوده است.

مثلاً:

قبل:

User C

└── Main CustomerAccount

بعد:

User C

├── Main CustomerAccount

│

└── Reseller Context

User جدید
نباید برای Reseller ساخته
شود.

شناسه
User همان شناسه
قبلی باقی می‌ماند.

---

**7. Reseller Context**

Reseller یک
Context عملیاتی
ایجاد می‌کند.

Reseller

    +

Existing User

    ↓

Reseller Context

این
Context شامل
مواردی مانند:

Reseller Bot

Reseller Customers

Customer Pricing

Reseller Operations

Reseller Store

است.

---

**8. StoreContext**

تمام
عملیات Store-scoped باید
از StoreContext استفاده
کنند.

مدل:

StoreContext

├── store_type

└── reseller_id

دو
حالت اصلی:

main

reseller

---

**9. Main Context**

store_type = main

reseller_id = null

مثلاً:

Ali / Main

---

**10. Reseller Context**

مثلاً:

store_type = reseller

reseller_id = 15

یعنی:

Ali / Reseller #15

---

**11. StoreContext Contract**

Context باید
بتواند مشخص کند:

isMain()

isReseller()

resellerId()

isOperational()

equals()

و برای
تمام عملیات مالی قابل استفاده باشد.

---

**12. CustomerAccount**

CustomerAccount نشان‌دهنده **عضویت یک User در یک StoreContext** است و مشخص می‌کند این User در کدام Store به‌عنوان Customer فعالیت می‌کند.

رابطه اصلی:

```text
User
+
StoreContext
=
CustomerAccount
```

بنابراین:

- `User` هویت اصلی سیستم است.
- `CustomerAccount` هویت تجاری User در یک StoreContext است.
- یک User می‌تواند هم‌زمان Customer چند Store باشد.
- Customer بودن در یک Reseller به‌صورت خودکار به معنی Customer بودن در Resellerهای دیگر نیست.
- `CustomerAccount` نباید به‌عنوان یک User مستقل در نظر گرفته شود.

ساختار مفهومی:

```text
CustomerAccount

├── id
├── user_id
├── store_type
├── reseller_id
├── status
├── created_at
└── updated_at
```

هر CustomerAccount باید به یک StoreContext مشخص وابسته باشد و تمام عملیات مربوط به Customer باید با همان Context انجام شود.

**13. CustomerAccount Uniqueness**

نباید
این Rule وجود
داشته باشد:

UNIQUE(user_id)

زیرا
یک User می‌تواند
در چند Store مشتری
باشد.

مثلاً:

User 100

├── Main

├── Reseller A

└── Reseller B

مجاز
است.

اما:

User 100 + Reseller A

نباید
دو بار ایجاد شود.

بنابراین
Unique بودن
باید مفهومی بر اساس:

user_id + StoreContext

باشد.

---

**14. Reseller Customer Scope**

Customer بودن
در یک Reseller به
معنی Customer بودن
در تمام Resellerها
نیست.

مثلاً:

Ali

├── Main

├── Reseller A

└── Reseller C

اگر
Ali عضو
Reseller A باشد،
نباید بتواند صرفاً با تغییر reseller_id به Customer Reseller B تبدیل شود.

Scope باید
در Core enforce شود.

---

**15. Identity Resolution**

IdentityService یا CustomerAccountService وظیفه دارد بر اساس هویت User و Context عملیاتی، CustomerAccount صحیح را پیدا یا ایجاد کند.

```text
User
+
StoreContext
↓
Resolve / Create
↓
CustomerAccount
```

Identity Resolution باید:

1. User موجود را شناسایی کند.
2. StoreContext جاری را مشخص کند.
3. بررسی کند آیا CustomerAccount متناظر وجود دارد یا خیر.
4. در صورت وجود، همان CustomerAccount را Resolve کند.
5. در صورت عدم وجود و مجاز بودن عضویت، CustomerAccount جدید ایجاد کند.
6. از ایجاد CustomerAccount تکراری جلوگیری کند.

Scope تجاری Reseller باید علاوه بر Identity Resolution در Purchase/Application Flow نیز بررسی و توسط Core enforce شود.

Identity Resolution نباید صرفاً با تغییر `reseller_id` باعث تبدیل یک User به Customer یک Reseller دیگر شود.

CustomerAccountهای متعلق به StoreContextهای مختلف نباید بدون احراز هویت و قواعد مشخص Identity با یکدیگر Merge شوند.

در صورت تشخیص اینکه Guest Website و یک User موجود در Core/Telegram متعلق به یک هویت هستند، اتصال Guest به User و سپس Resolve/Create شدن CustomerAccount باید فقط از طریق یک فرآیند معتبر، قابل ردیابی و Idempotent انجام شود.

**16. Guest**

Guest یک هویت موقت و میهمان است که در **Website و قبل از ایجاد/احراز User دائمی** مورد استفاده قرار می‌گیرد.

Guest:

- می‌تواند بدون Registration وارد فرآیند خرید شود.
- می‌تواند بدون داشتن CustomerAccount خرید خود را انجام دهد.
- نباید صرفاً به دلیل Guest بودن، یک User دائمی ایجاد کند.
- Guest Identity نباید با User Identity دائمی اشتباه گرفته شود.
- اطلاعات Guest باید به‌صورت محدود و مرتبط با جریان خرید نگهداری شود.

جریان اصلی:

```text
Website
   ↓
Guest
   ↓
Checkout
   ↓
Order
   ↓
Payment
   ↓
Provisioning
```

Guest برای ادامه خرید **نیازمند CustomerAccount نیست**.

پس از خرید موفق، سیستم باید برای تبدیل یا اتصال Guest به User و سپس Resolve/Create کردن CustomerAccount مربوط به StoreContext تلاش کند؛ اما موفق نبودن این فرآیند نباید باعث شود خرید موفق Guest یا ارائه سرویس او از بین برود.

در صورتی که User از طریق Telegram وارد سیستم شود، User مربوطه وارد جریان CustomerAccount می‌شود و CustomerAccount مربوط به StoreContext عملیاتی او ایجاد یا Resolve خواهد شد.

اگر بعداً با یک فرآیند معتبر مشخص شود که Guest Website همان User موجود در Telegram/Core است، اطلاعات مربوط باید طبق قواعد Identity Resolution به همان User متصل شده و CustomerAccount مربوط به StoreContext ایجاد یا Resolve شود.

**اولویت این بند با بندهای مربوط به Website یکسان است.**

**17. Guest Order**

Guest می‌تواند **بدون Registration و بدون داشتن CustomerAccount** خرید خود را انجام دهد.

```text
Guest
   ↓
Product
   ↓
Checkout
   ↓
Order
   ↓
Payment
   ↓
Provisioning
```

بنابراین وجود CustomerAccount شرط لازم برای ایجاد یا تکمیل خرید Guest نیست.

پس از موفقیت خرید، سیستم باید برای تبدیل/اتصال Guest به یک User موجود یا ایجاد User جدید و سپس ایجاد/Resolve کردن CustomerAccount مربوط به StoreContext تلاش کند.

این فرآیند باید:

- Idempotent باشد.
- باعث ایجاد User یا CustomerAccount تکراری نشود.
- باعث Merge اشتباه اطلاعات دو شخص مختلف نشود.
- StoreContext صحیح را حفظ کند.
- Guest data را با User دیگری اشتباه نگیرد.

در صورت Registration موفق:

```text
Guest
 ↓
Register
 ↓
User
 ↓
CustomerAccount
```

در صورت تشخیص معتبر یک User موجود:

```text
Guest
 ↓
Identity Resolution
 ↓
Existing User
 ↓
CustomerAccount
```

در صورتی که سیستم نتواند هویت Guest را با اطمینان کافی به User موجود متصل کند، سرویس خرید انجام‌شده باید همچنان معتبر باقی بماند.

اطلاعاتی مانند IP، Browser/Device Signals، Cookie، Session و Checkout Token فقط برای Session، تشخیص احتمالی یا جلوگیری از Duplicateهای ساده قابل استفاده‌اند و به‌تنهایی اثبات قطعی هویت محسوب نمی‌شوند.

**اولویت این بند با بندهای مربوط به Website یکسان است.**

**18. سه قیمت نهایی سیستم**

Melorin فقط سه
مفهوم Pricing دارد:

main_price

reseller_price

customers_price

این سه
مفهوم مستقل هستند.

---

**19. main_price**

main_price قیمت
فروش مستقیم Product از
Main به
Customer در
Main Context است.

مثلاً:

main_price = 12

خرید:

Ali

 ↓

Main

 ↓

Ali Main Wallet

 ↓

\- main_price

---

**20. reseller_price**

reseller_price قیمت تأمین Product برای Reseller از Main است.

مثلاً:

reseller_price = 10

در
خرید Reseller:

Reseller Owner

 ↓

Main Context

 ↓

Owner Main Wallet

 ↓

\- reseller_price

---

**21. customers_price**

customers_price قیمت فروش Reseller به Customer خودش است.

مثلاً:

customers_price = 14

خرید:

Customer

 ↓

Reseller Context

 ↓

Customer Wallet

 ↓

\- customers_price

---

**22. تفاوت سه قیمت**

|      |
| ---- |

**قیمتمفهوممحل
&#x20;  استفاده**

|     |   |
| --- | - |
|     |   |

main_price

|     |
| --- |

فروش
&#x20; مستقیم Main

|     |
| --- |

Main Customer

|     |
| --- |

reseller_price

|     |
| --- |

تأمین
&#x20; Reseller از
&#x20; Main

|     |
| --- |

Reseller Owner در Main

|     |
| --- |

customers_price

|     |
| --- |

فروش
&#x20; Reseller به
&#x20; Customer

|     |
| --- |

Customer در
&#x20; Reseller Context

---

**23. Legacy Pricing Names**

نام‌های
زیر در معماری جدید ممنوع هستند:

base_price

core_price

sold_price

custom_price

corePrice

soldPrice

sellingPriceForReseller

هیچ
Alias یا
Backward Compatibility برای
این نام‌ها نگهداری نمی‌شود.

---

**24. Product Pricing API**

Product باید
API مفهومی زیر را
داشته باشد:

mainPrice()

resellerPrice()

customersPrice($reseller)

معادل:

mainPrice()

→ main_price

resellerPrice()

→ reseller_price

customersPrice()

→ customers_price

---

**25. Reseller Product Pricing**

Reseller Product جدید ایجاد نمی‌کند.

Reseller از
Productهای
Core استفاده می‌کند.

Reseller فقط می‌تواند
قیمت فروش خودش را برای Customer تعیین کند:

Product X

    │

    ├── main_price

    ├── reseller_price

    │

    └── Reseller A

            └──
customers_price

---

**26. Wallet Architecture — اصل نهایی**

Wallet یک
موجودیت عمومی است.

نام
فنی:

wallet

نه:

customer_wallet

reseller_wallet

customer_reseller_wallet

main_customer_wallet

Context مشخص
می‌کند Wallet متعلق
به کدام Store است.

مدل
مفهومی:

Wallet

├── id

├── user_id

├── store_type

├── reseller_id

└── balance

---

**27. Wallet = User + StoreContext**

اصل
نهایی:

Wallet

\=

User

\+

StoreContext

مثلاً:

Ali / Main

Ali / Reseller A

Ali / Reseller B

هرکدام
Wallet مستقل
دارند.

---

**28. Main Wallet**

برای
User C:

user_id = C

store_type = main

reseller_id = null

این
Wallet همان
Wallet مورد
استفاده C برای
پرداخت reseller_price است.

---

**29. Reseller Context Wallet**

برای
Ali در
Reseller A:

user_id = Ali

store_type = reseller

reseller_id = A

برای
Ali در
Reseller C:

user_id = Ali

store_type = reseller

reseller_id = C

این
Walletها
مستقل هستند.

---

**30. Wallet Independence**

فرض:

User 100 / Main      
\= 500

User 100 / Reseller A = 200

User 100 / Reseller B = 750

User 100 / Reseller C = 100

Debit در:

User 100 / Reseller A

نباید
موجودی:

User 100 / Main

را
تغییر دهد.

---

**31. Wallet صاحب Reseller**

این
بخش مهم است:

برای
پرداخت:

reseller_price

Wallet صاحب
Reseller یک
Wallet مستقل
با نام خاص نیست.

بلکه:

Owner User

\+

Main Context

\=

Wallet

بنابراین:

C / Main Wallet

محل
Debit reseller_price است.

---

**32. Walletهای ممنوع**

نباید
مدل‌هایی مانند این ایجاد شوند:

customer_wallet_main

customer_reseller_wallet

reseller_wallet

main_reseller_wallet

فقط:

wallet

با
Context.

---

**33. Wallet Uniqueness**

هدف:

یک
User

\+

یک
StoreContext

\=

یک
Wallet

است.

در
MySQL باید
رفتار NULL در
Unique Index در نظر
گرفته شود.

به‌خصوص:

store_type = main

reseller_id = null

نباید
اجازه ایجاد چند Main Wallet برای
یک User را
بدهد.

---

**34. WalletService**

Service اصلی:

WalletService

مسئول:

credit()

debit()

canDebit()

adjust()

getBalance()

و
Resolve Wallet بر
اساس:

User + StoreContext

است.

---

**35. Financial Ledger**

هر
تغییر موجودی باید Ledger داشته باشد.

ممنوع:

$wallet->balance -= 100;

بدون
ثبت Financial Operation.

مجاز:

WalletService

 ↓

Wallet Transaction

\+

Balance Update

---

**36. WalletTransaction**

ساختار
مفهومی:

WalletTransaction

├── id

├── wallet_id

├── type

├── amount

├── balance_after

├── reference_type

├── reference_id

├── description

└── created_at

Typeهای
اصلی می‌توانند شامل:

charge

purchase

refund

commission

referral_bonus

admin_adjust

renewal

purchase_reversal

باشند.

---

**37. Admin Wallet Adjustment**

Admin می‌تواند
طبق Permission موجودی
Wallet را
Adjust کند.

این
عملیات باید:

Wallet Update

\+

Ledger

\+

Audit

داشته
باشد.

---

**38. Reseller Wallet Top-up**

Reseller نباید
مستقیماً از طریق یک Wallet مخصوص Reseller، موجودی مستقل ایجاد کند.

Wallet مورد
استفاده او برای reseller_price همان Main Wallet اوست.

Top-up آن نیز
باید طبق Financial Flow رسمی Main انجام
شود.

---

**39. Main Purchase Flow**

Customer

 ↓

Main Context

 ↓

CustomerAccount

 ↓

Product

 ↓

main_price

 ↓

PurchaseGuard

 ↓

Main Wallet Debit

 ↓

Order

 ↓

Provisioning

---

**40. Main Purchase Financial Rule**

برای
خرید مستقیم:

Debit = main_price

و:

reseller_price

customers_price

نقشی
ندارند.

---

**41. Reseller Purchase Flow**

فرض:

reseller_price = 10

customers_price = 14

جریان:

Customer

 ↓

Reseller A Context

 ↓

customers_price = 14

 ↓

Customer Wallet

 ↓

-14

و:

Reseller A Owner

 ↓

Main Context

 ↓

reseller_price = 10

 ↓

Owner Main Wallet

 ↓

-10

---

**42. Double Debit**

خرید
Customer از
Reseller شامل
دو Debit مستقل
است:

Customer Wallet / Reseller Context

→ -customers_price

و:

Reseller Owner Wallet / Main Context

→ -reseller_price

این دو
Debit نباید
با هم ادغام شوند.

---

**43. Reseller Profit**

فرمول:

customers_price - reseller_price

مثلاً:

14 - 10 = 4

این
مقدار حاشیه فروش است.

اما
Debitها:

Customer → 14

Reseller → 10

باقی
می‌مانند.

---

**44. Reseller Purchase by Owner**

اگر
خود Reseller از
Main خرید کند:

Reseller Owner

 ↓

Main Context

 ↓

reseller_price

 ↓

Owner Main Wallet

یعنی:

Debit = reseller_price

---

**45. PurchaseGuard**

PurchaseGuard باید قبل از Debit موارد زیر را بررسی کند:

Store Context

Customer Scope

Customer Status

Product Availability

Category Availability

Sale Limit

Account Limit

Customer Balance

Reseller Debt Limit

Server Eligibility

هیچ
Debit قبل از
Validation کامل
انجام نشود.

**تکمیل
این بند پس از ساخت Website نیز باید بررسی و تکمیل شود.**

---

**46. Reseller Debt Limit**

Reseller ممکن
است طبق debt_limit اجازه
موجودی منفی مشخصی داشته باشد.

مثلاً:

Current = -450

Debt Limit = 500

Purchase:

10

مجاز
است.

اما
اگر:

Current = -495

Purchase = 10

باشد:

New Balance = -505

و باید
Block شود.

---

**47. Debt Limit فقط Purchase را متوقف می‌کند**

رسیدن
به Debt Limit نباید
لزوماً:

Reseller

→ Disabled

کند.

بلکه:

Purchase

→ Block

می‌شود.

و
عملیات Admin مانند:

Credit

Adjust

Refund

طبق
Permission می‌تواند
همچنان انجام شود.

---

**48. Reseller Balance Warning**

سیستم
باید قبل از رسیدن به Debt Limit Warning بدهد.

مثلاً:

balance <= warning_threshold

ولی
Warning با
Block یکی
نیست.

**اصلاح
لازم:**

Warning باید
به‌صورت یک وضعیت/هشدار مستقل از Block پیاده‌سازی و تست شود.

نمونه:

Current Balance

      ↓

Warning Threshold

      ↓

Warning

      ↓

Debt Limit

      ↓

Purchase Block

---

**49. Order**

Order باید
Context و قیمت
Snapshot شده را
نگهداری کند.

**Main Order**

context = main

reseller_id = null

main_price = 12

reseller_price = null

customers_price = null

**Reseller Order**

context = reseller

reseller_id = A

main_price = null

reseller_price = 10

customers_price = 14

---

**50. Price Snapshot**

Order باید
قیمت لحظه خرید را Snapshot کند.

برای
Main:

main_price

برای
Reseller:

reseller_price

customers_price

تغییر
قیمت Product در
آینده نباید Order قدیمی
را تغییر دهد.

---

**51. Payment**

Payment باید
به Order و در
صورت وجود به CustomerAccount مرتبط باشد.

مفاهیم:

Payment

├── customer_account_id

├── order_id

├── payment_method_id

├── amount

├── purpose

├── status

└── audit information

**اصلاح
لازم — اولویت 2:**

Payment باید
به‌صورت کامل با State Machine تعریف‌شده
در سند منطبق شود و تغییر State فقط از مسیر مشخص Payment State Machine
انجام شود.

Stateهای
واقعی، Transitionهای
مجاز و ارتباط Payment با
Order باید
یک Contract واحد
داشته باشند و Implementation و Test نیز
دقیقاً از همان Contract استفاده
کنند.

---

**52. Payment Purpose**

حداقل:

order

wallet_charge

**اصلاح
لازم — اولویت 2:**

هر
Payment باید
Purpose مشخص و
معتبر داشته باشد و Flow مربوط به order و wallet_charge از یکدیگر تفکیک شود.

برای order، ارتباط
Payment با
Order و
وضعیت Purchase باید
مشخص باشد.

برای wallet_charge، نتیجه
Payment باید
صرفاً موجب افزایش موجودی Wallet مربوط به Context صحیح شود.

---

**53. Payment State Machine**

مدل:

created

 ↓

pending

 ↓

processing

 ↓

confirmed

یا:

pending

 ↓

rejected

و پس
از Confirmation:

confirmed

 ↓

refunded

در
صورت نیاز:

partially_refunded

**اصلاح
لازم — اولویت 2:**

Payment State Machine باید از حالت صرفاً مستند خارج شده و به **Single
Source of Truth برای
تغییر وضعیت Payment** تبدیل
شود.

Transitionهای
مجاز باید مشخص و enforce شوند:

created

 ↓

pending

 ↓

processing

 ↓

confirmed

یا:

pending

 ↓

rejected

و:

confirmed

 ↓

refunded

در
صورت نیاز:

partially_refunded

هیچ
Service یا
Channel نباید
مستقل از Payment State Machine وضعیت Payment را تغییر دهد.

همچنین
وضعیت‌های واقعی Database و
Code باید با این
State Machine یکسان‌سازی
شوند.

---

**54. Card-to-Card**

در پرداخت Card-to-Card، کاربر فقط باید **تصویر رسید پرداخت** را Upload کند.

جریان:

```text
Card-to-Card
   ↓
Upload Receipt Image
   ↓
Payment = Pending
   ↓
Admin Review
   ├── Confirmed
   └── Rejected
```

Confirmation باید:

- توسط Admin احراز‌شده انجام شود.
- Audit-able باشد.
- قابل ردیابی باشد.
- از Confirmation تکراری جلوگیری شود.

Website و Telegram فقط رابط دریافت تصویر رسید و نمایش وضعیت هستند و منطق تأیید پرداخت باید در Core قرار داشته باشد.

**55. Direct Payment و Wallet**

دو مسیر مالی اصلی مستقل هستند:

```text
Wallet Payment
```

و:

```text
Direct Payment
```

Partial Wallet + Direct Payment فعلاً در Scope اصلی پیاده‌سازی نیست.

مسیرهای Wallet Payment و Direct Payment باید از نظر معماری کاملاً مستقل و مشخص باشند.

Implementation باید مشخص کند:

```text
Wallet Payment
→ Wallet Debit
→ Purchase
```

و:

```text
Direct Payment
→ Payment Gateway / Manual Payment
→ Payment Confirmation
→ Purchase
```

به‌صورت جداگانه عمل می‌کنند.

Partial Wallet + Direct Payment همچنان خارج از Scope اصلی باقی می‌ماند.

**56. PurchaseService**

PurchaseService مرکز اجرای خرید است.

جریان:

PurchaseService

 ↓

Resolve StoreContext

 ↓

Resolve CustomerAccount

 ↓

Validate Scope

 ↓

Resolve Product

 ↓

Resolve Pricing

 ↓

PurchaseGuard

 ↓

Create Order

 ↓

Financial Operation

 ↓

Provisioning

 ↓

Referral / Commission

 ↓

Notification

---

**57. Purchase Atomicity**

خرید
Reseller از نظر
تجاری یک عملیات واحد است.

ترتیب:

BEGIN TRANSACTION

1\. Resolve Context

2\. Validate Customer

3\. Validate Reseller

4\. Validate Scope

5\. Resolve Product

6\. Resolve customers_price

7\. Resolve reseller_price

8\. Resolve Customer Wallet

9\. Resolve Reseller Owner Main Wallet

10\. Validate Customer Balance

11\. Validate Reseller Debt

12\. Debit Customer

13\. Debit Reseller

14\. Create Order

15\. Create Financial Operations

16\. Commit Financial State

END

Provisioning می‌تواند Lifecycle مستقل خود را داشته باشد.

---

**58. Financial Atomicity**

نباید
این حالت رخ دهد:

Customer Debit = success

Reseller Debit = failed

یا:

Reseller Debit = success

Customer Debit = failed

قبل از
Commit باید
هر دو Debit قابل
انجام باشند.

---

**59. Idempotency**

هر
Purchase باید
Operation Identifier داشته
باشد.

مثلاً:

purchase_operation_id

اجرای
دوباره همان عملیات نباید:

Duplicate Debit

Duplicate Order

Duplicate Payment

ایجاد
کند.

---

**60. Webhook Idempotency**

Webhookهای
Payment نیز
باید Idempotent باشند.

اگر یک
Webhook دوبار
دریافت شد:

Payment

→ فقط یک
بار Confirm

شود.

---

**61. Sale Limit**

اگر
Product دارای:

sale_limit = 100

باشد و:

sold = 100

خرید
بعدی باید Block شود.

این
Rule باید در
Core enforce شود،
نه فقط در UI.

**اصلاح
لازم — اولویت 3:**

بررسی
Sale Limit باید
در برابر Race Condition نیز امن شود.

دو
Purchase همزمان
نباید بتوانند یک ظرفیت باقی‌مانده را هم‌زمان مصرف کنند.

برای
این منظور باید از Reservation، Atomic Counter، Row Lock یا مکانیزم معادل استفاده شود.

---

**62. Account Limit**

اگر
محدودیت Account وجود
داشته باشد:

account_limit_per_user = 3

Core باید
تعداد Accountهای
مرتبط را بررسی کند.

UI به‌تنهایی
کافی نیست.

**اصلاح
معماری:**

در
Website، Guest
می‌تواند برای
جریان قبل از ثبت‌نام دارای CustomerAccount موقت/محدود باشد.

این
CustomerAccount برای
Guest صرفاً
جهت ادامه Checkout استفاده
می‌شود و باید در نهایت کاربر را به سمت Register/Login هدایت کند.

بعد از
ثبت‌نام:

Guest

 ↓

Register

 ↓

User

 ↓

CustomerAccount

CustomerAccount باید به Identity واقعی متصل شود.

این
بند در ارتباط مستقیم با Website است.

---

**63. Server Eligibility**

قبل از
Provisioning باید
Server واجد
شرایط باشد.

Strategy فعلی
عمدتاً روی:

Active

Active Accounts

تمرکز
دارد و برای وضعیت فعلی پروژه کافی است.

---

**64. Server Selection**

Selection فقط از
Eligible Serverها
انجام شود:

Eligible Servers

 ↓

Selection Strategy

 ↓

Selected Server

Strategy فعلی
می‌تواند:

LeastActiveAccountsStrategy

باشد.

**اصلاح
لازم — اولویت 3:**

Strategy فعلی
حفظ شود، اما انتخاب Server باید به‌صورت مشخص و قابل تست انجام شود تا
Server غیرفعال
انتخاب نشود و Race Condition در انتخاب Server باعث تخصیص نادرست نشود.

---

**65. Capacity**

ظرفیت
باید در زمان Provisioning/Reserve به‌صورت امن بررسی شود تا Race Condition ایجاد نشود.

دو
Purchase همزمان
نباید بتوانند آخرین ظرفیت را دوبار مصرف کنند.

**اصلاح
لازم — اولویت 2:**

برای
Capacity باید
مکانیزم Reservation/Locking/Atomic Update ایجاد شود.

جریان
پیشنهادی:

Check Capacity

     ↓

Reserve Capacity

     ↓

Provision

     ↓

Success → Consume Reservation

     ↓

Failure → Release Reservation

رزرو
ظرفیت باید Atomic باشد
تا دو Purchase همزمان
نتوانند آخرین ظرفیت را دوبار مصرف کنند.

---

**66. VPN Panel Abstraction**

Core نباید
مستقیماً به API یک
Panel خاص
وابسته باشد.

Interface:

VpnPanelDriver

و
Driverهای
مختلف:

Sanaei

Marzban

PasarGuard

SoftEther

---

**67. ProvisioningService**

Provisioning باید Service مستقل
داشته باشد.

مدل:

Purchase

 ↓

ProvisioningService

 ↓

VpnPanelDriver

 ↓

VPN Account

---

**68. Provisioning Lifecycle**

Provisioning نباید صرفاً با Order Status کنترل شود.

باید
Operation/State مستقل
داشته باشد.

مثلاً:

pending

provisioning

provisioned

failed

---

**69. Provisioning Attempt Tracking**

هر
تلاش Provisioning باید
قابل ثبت باشد:

ProvisioningAttempt

├── operation_id

├── attempt_number

├── status

├── error

├── started_at

└── finished_at

**اصلاح
لازم — اولویت 3:**

برای
هر Attempt واقعی
Provisioning باید
اطلاعات Attempt به‌صورت
قابل Audit و
Debug ثبت
شود.

حداقل:

operation_id

attempt_number

status

error

started_at

finished_at

همچنین
باید مشخص باشد Retry شماره
چند است و Retry مجدد
نباید Debit جدید
ایجاد کند.

---

**70. Provisioning Retry**

Retry باید
Idempotent باشد.

حداکثر
تلاش عملیاتی:

3 attempts

پس از
Failureهای
متوالی:

Admin Notification

و
وضعیت مشخص Failure ثبت
شود.

---

**71. Provisioning Failure Policy**

سیستم
حداقل دو Policy دارد:

retry

refund

**Retry**

Provisioning Failed

→ provision_failed

→ Retry

بدون
Debit دوباره.

**Refund**

Provisioning Failed

→ Refund

---

**72. Refund در Main**

اگر
Main Purchase باشد:

Refund = main_price

به
Wallet مشتری
در Main Context.

---

**73. Refund در Reseller**

اگر
Reseller Purchase باشد:

Customer Refund

\= customers_price

و:

Reseller Owner Refund

\= reseller_price

به
Walletهای
مربوط به Context صحیح.

---

**74. Refund از Snapshot**

Refund نباید
بر اساس قیمت فعلی Product انجام شود.

باید
از:

Order Price Snapshot

استفاده
کند.

---

**75. Retry بدون Debit مجدد**

اگر
Purchase قبلاً
Debit شده
باشد و Provisioning Retry شود:

Customer Debit = 0

Reseller Debit = 0

در
Retry.

فقط
Provisioning مجدداً
اجرا می‌شود.

---

**76. Account Lifecycle**

Account متعلق
به CustomerAccount و StoreContext است.

هیچ
Account نباید
بدون Context معتبر
باقی بماند، مگر Guest Provisioning موقت.

---

**77. Account Expiration**

Account Lifecycle باید شامل:

active

expired

disabled

باشد.

Expiration باید
مستقل از CustomerAccount Status مدیریت شود.

Suspend کردن
CustomerAccount نباید
بدون Rule مشخص
Accountهای
قبلی را حذف یا Disable کند.

---

**78. Traffic Synchronization**

برای
VPN Account:

Panel Usage

باید
با:

Melorin Account

قابل
Synchronize باشد.

---

**79. Renewal**

Renewal Purchase ساده نیست.

باید
Service مستقل
داشته باشد:

RenewalService

---

**80. Renewal Main**

در
Main:

Main Context

 ↓

main_price

 ↓

Main Wallet / Payment

 ↓

Renewal

---

**81. Renewal Reseller**

در
Reseller:

Reseller Context

 ↓

customers_price

 ↓

Customer Wallet

و هزینه
تأمین:

reseller_price

 ↓

Reseller Owner Main Wallet

---

**82. Renewal باید Time و Traffic را مدیریت کند**

Renewal موفق
باید طبق Product:

expires_at

را
Extend/Reset کند و:

traffic

را نیز
طبق Rule محصول
Reset/Update کند.

---

**83. Renewal Failure**

اگر
Payment موفق
شد ولی Panel Renewal شکست
خورد، Renewal باید
State/Operation مستقل
داشته باشد.

مثلاً:

renewal_pending

renewal_processing

renewal_success

renewal_failed

و
Compensation/Retry طبق
Policy مشخص
انجام شود.

---

**84. Referral**

Referral و
Commission دو
مفهوم مستقل هستند.

Referral:

Relationship / Attribution

Commission:

Financial Reward

---

**85. Referral Attribution**

صرف
داشتن:

/start?referrer=...

نباید
الزاماً Commission ایجاد
کند.

Commission فقط در
Purchase واجد
شرایط ایجاد می‌شود.

---

**86. Commission**

Commission باید
Service مستقل
داشته باشد.

مثلاً:

Commission Rate = 10%

و در
Purchase:

commission_amount

محاسبه
می‌شود.

---

**87. Commission Snapshot**

Commission باید
در زمان Purchase Snapshot شود:

commission_rate

commission_amount

تغییر
Rate در آینده نباید
Commission قبلی
را تغییر دهد.

---

**88. Double Commission Prevention**

Retry یا
Webhook تکراری
نباید Commission دوم
ایجاد کند.

برای
هر Purchase واجد
شرایط:

One Commission Record

---

**89. Commission Refund Rule**

طبق
تصمیم فعلی:

Purchase Refund

≠

Commission Reversal

Commission پس از
Refund خودکار
Reverse نمی‌شود.

بنابراین:

Commission

و:

Refund

دو
عملیات مستقل هستند.

---

**90. Referral Bonus**

Referral Bonus نیز با Commission یکی نیست.

مثلاً:

Referral Bonus

می‌تواند
یک Reward مستقل
باشد.

ساختار
و Logic آن
نباید با Commission مخلوط
شود.

---

**91. Discount**

Google Discount و سایر Discountها باید در Core محاسبه شوند.

Discount نباید
توسط Bot یا
Website به‌صورت
مستقل محاسبه شود.

**اصلاح
لازم — اولویت 5:**

Discount باید
به‌صورت یک سرویس مشخص در Core پیاده‌سازی شود و محاسبه Discount از
Channelها
خارج شود.

---

**92. Discount Eligibility**

Eligibility باید در Core تعیین شود.

مثلاً:

First Purchase

Every Purchase

بسته
به Setting.

**اصلاح
لازم — اولویت 5:**

قواعد
Eligibility باید
به‌صورت صریح در Core تعریف
و تست شوند.

حداقل
باید مشخص شود Discount برای:

First Purchase

Every Purchase

یا
سایر شرایط دقیقاً چه زمانی مجاز است.

---

**93. First Purchase**

اگر
Discount فقط
برای اولین خرید باشد:

Customer

→ Has Previous Eligible Purchase?

باید
توسط Core بررسی
شود.

**اصلاح
لازم — اولویت 5:**

First Purchase باید بر اساس سابقه واقعی Purchaseهای واجد شرایط بررسی شود و نباید صرفاً به
UI یا
Session متکی
باشد.

---

**94. Discount Stacking**

قواعد
ترکیب Discountها
باید صریح باشند.

در
صورت عدم تصمیم قطعی، Implementation نباید رفتار دلخواه ایجاد کند.

**اصلاح
لازم — اولویت 5:**

قواعد
ترکیب Discountها
باید قبل از Implementation نهایی مشخص شوند.

مثلاً
باید مشخص شود:

Discount A + Discount B

آیا:

Allowed

است یا:

Not Allowed

و در
صورت Allowed بودن،
ترتیب اعمال Discountها نیز
مشخص شود.

---

**95. Payment و Purchase Separation**

Payment و Purchase دو مفهوم مستقل هستند و موفقیت یکی نباید به‌صورت خودکار به معنی موفقیت کامل دیگری باشد.

جریان کلی:

```text
Payment
   ↓
Purchase
   ↓
Provisioning
```

هر مرحله باید State مستقل خود را داشته باشد.

بنابراین:

- Payment State مستقل است.
- Purchase State مستقل است.
- Provisioning State مستقل است.
- Payment Confirmation نباید خارج از Core منطق Business ایجاد کند.
- `Payment = Confirmed` لزوماً به معنی `Purchase = Completed` یا `Provisioning = Completed` نیست.
- Retry یک مرحله نباید باعث ایجاد Debit یا Purchase تکراری شود.
- ارتباط Payment با Order، Purchase و Purpose باید توسط Core enforce شود.

در Wallet Payment، Debit باید طبق قواعد مالی Core انجام شود.

در Direct Payment، Payment مسیر مستقل خود را دارد و پس از Confirmation، Purchase طبق Contract Core انجام می‌شود.

**96. Order Success**

Order نباید
صرفاً با Payment موفق،
موفق نهایی تلقی شود اگر Provisioning هنوز تعیین تکلیف نشده است.

مثلاً:

Payment = confirmed

Provisioning = pending

یعنی:

Order = awaiting provisioning

---

**97. Reseller Product Creation ممنوع**

Reseller نمی‌تواند
Product جدید
Core ایجاد کند.

Reseller فقط:

Core Products

\+

customers_price

را
مدیریت می‌کند.

---

**98. Reseller Data Boundary**

Reseller فقط
باید داده‌های مربوط به خودش را مشاهده کند:

Own Customers

Own Orders

Own Customer Accounts

Own Store Context

Own Pricing

Own Store Settings

نباید
بتواند:

Other Reseller Customers

Other Reseller Orders

Other Reseller Pricing

را
مشاهده یا تغییر دهد.

---

**99. Main Admin**

Main Admin می‌تواند
طبق Permission:

Users

CustomerAccounts

Products

Categories

Resellers

Wallets

Payments

Orders

Provisioning

را
مدیریت کند.

تمام
عملیات حساس باید Audit شوند.

---

**100. Audit Log**

عملیات
حساس:

Wallet Adjustment

Payment Confirmation

Refund

Reseller Modification

Product Price Change

Customer Exceptional Management

Account Disable/Delete

باید
Audit داشته
باشند.

---

**101. Logging**

Log نباید
شامل:

password

bot token

webhook secret

payment secret

باشد.

Log باید
برای:

Order

Payment

Operation

Provisioning

Retry

Failure

قابل
استفاده باشد.

---

**102. Database Migration**

Migration باید
تدریجی و قابل Rollback باشد.

اصل
مهم:

اطلاعات
فعلی نباید بدون Backup و
Validation حذف
شوند.

---

**103. Migration CustomerAccount**

ابتدا
CustomerAccountهای
Store-scoped ایجاد
شوند.

برای
هر User:

Main CustomerAccount

و در
صورت وجود سابقه:

Reseller CustomerAccount

ایجاد
شود.

---

**104. Migration Wallet**

Walletهای
موجود باید به Context صحیح
Map شوند.

هدف:

User + Main

و:

User + Reseller

با
موجودی مستقل.

هیچ
Wallet بدون
Owner/Context معتبر
نباید باقی بماند.

---

**105. Migration Order**

Orderهای
قدیمی باید به:

CustomerAccount

StoreContext

Pricing Snapshot

متصل
شوند.

---

**106. Migration Pricing**

Migration قیمت‌ها
باید Semantic باشد.

نباید
صرفاً Rename مکانیکی
انجام شود.

هدف
نهایی:

main_price

reseller_price

customers_price

است.

اگر
داده قدیمی معنای متفاوتی داشته باشد، باید بر اساس Context و سابقه
Order به
مفهوم صحیح Map شود.

---

**107. Migration Payment**

Paymentهای
قدیمی باید به:

CustomerAccount

Order

Payment Method

Context

متصل
شوند.

---

**108. Migration Commission**

Commissionهای
قدیمی باید:

commission_rate

commission_amount

purchase/order reference

داشته
باشند.

Rate تاریخی
نباید با Rate فعلی
جایگزین شود.

---

**109. Migration Validation**

بعد از
Migration باید
بررسی شود:

Users preserved

CustomerAccounts correct

Wallet ownership correct

Wallet balances correct

Orders correct

Payments correct

Accounts correct

Reseller separation correct

Commission correct

---

**110. Migration Timestamp**

Migrationهایی
که Timestamp آینده
دارند باید قبل از Production بررسی شوند.

نباید
Migrationها
کورکورانه اجرا شوند.

ترتیب
Migration باید
dependency-aware باشد.

---

**111. Service Directory**

ساختار
پیشنهادی:

app/

├── Services/

│   ├── Core/

│   │   ├──
Purchase/

│   │   ├──
Renewal/

│   │   ├──
Wallet/

│   │   ├──
Payment/

│   │   ├──
Provisioning/

│   │   └── Identity/

│   │

│   └── Resellers/

│

├── Models/

├── Channels/

├── Jobs/

└── Filament/

---

**112. Transaction Boundary**

Financial عملیات
باید Transaction Boundary مشخص داشته باشند.

مثلاً:

Purchase Financial Transaction

شامل:

Debit Customer

Debit Reseller

Create Order

Create Financial Operations

باشد.

Provisioning می‌تواند بعد از Commit مالی با Operation State مستقل اجرا شود.

---

**113. Operation / Outbox**

برای
عملیات حساس می‌توان Operation Record داشت:

operation_id

type

status

reference

attempts

error

این
مدل برای:

Purchase

Provisioning

Renewal

Refund

Webhook

مفید
است.

---

**114. Background Jobs**

کارهای
سنگین/قابل Retry باید
Job باشند:

ProvisioningJob

ProvisioningRetryJob

RenewalJob

NotificationJob

BroadcastJob

Webhook Processing

Jobها
نباید Financial Debit مستقل از Core ایجاد کنند.

---

**115. Channel Boundary**

Main Bot:

Handler

 ↓

Application Service

Reseller Bot:

Handler

 ↓

Application Service

Website:

Controller

 ↓

Application Service

Admin:

Action

 ↓

Application Service

همه
باید از Core Rule مشترک
استفاده کنند.

---

**116. Security**

قبل از
Production:

.env

Secrets

Bot Tokens

Webhook Secrets

Payment Secrets

Admin Permissions

Customer Boundaries

Reseller Boundaries

بررسی
شوند.

اگر
Secretی
Exposure داشته
باشد باید Rotate شود.

---

**117. Test Strategy — CustomerAccount**

حداقل:

Same User + Main

Same User + Reseller A

Same User + Reseller B

باید
مستقل باشند.

---

**118. Test Strategy — Wallet**

باید
تست شود:

Credit

Debit

Insufficient Balance

Admin Adjustment

Negative Reseller Balance

Debt Limit

Ledger Consistency

Context Isolation

---

**119. Test Strategy — Reseller Purchase**

سناریوی
موفق:

customers_price = 130

reseller_price = 100

Customer → -130

Reseller Owner Main → -100

سناریوی
Debt Limit:

Reseller Balance = -450

Debt Limit = 500

Cost = 50

→ Allowed

و:

Reseller Balance = -450

Debt Limit = 500

Cost = 51

→ Blocked

در
حالت Block:

Customer Debit = 0

Reseller Debit = 0

Provisioning = 0

---

**120. Test Strategy — Provisioning**

باید
تست شود:

First Attempt Success

Failure → Retry

Failure ×2 → Retry

Failure ×3 → Admin Notification

Duplicate Retry → No Duplicate Debit

---

**121. Test Strategy — Renewal**

باید
تست شود:

Successful Renewal

Wallet Renewal

Direct Payment Renewal

Traffic Reset

Time Extension

Panel Failure

Retry

Duplicate Request

---

**122. Test Strategy — Commission**

باید
تست شود:

Commission Rate Snapshot

Commission Amount Snapshot

Duplicate Prevention

Refund Does Not Reverse Commission

---

**123. Test Strategy — Guest**

تست Guest باید مستقیماً با Website و Core انجام شود.

حداقل سناریوهای زیر باید پوشش داده شوند:

### Guest Checkout

```text
Website
 ↓
Guest
 ↓
Product
 ↓
Checkout
```

### Guest Purchase بدون Registration

```text
Guest
 ↓
Checkout
 ↓
Payment
 ↓
Order
 ↓
Provisioning
```

باید تأیید شود که Guest می‌تواند بدون Registration و بدون CustomerAccount خرید کند.

### Guest Post-Purchase Identity Resolution

```text
Successful Purchase
 ↓
Identity Resolution
 ↓
User
 ↓
CustomerAccount
```

باید بررسی شود:

- User موجود قابل شناسایی است یا خیر.
- Guest می‌تواند به User موجود متصل شود یا خیر.
- CustomerAccount صحیح ایجاد/Resolve می‌شود یا خیر.
- Duplicate User یا CustomerAccount ایجاد نمی‌شود.
- StoreContext صحیح حفظ می‌شود.

### Guest Registration

```text
Guest
 ↓
Register
 ↓
User
 ↓
CustomerAccount
```

### Guest با Telegram User موجود

```text
Website Guest
 ↓
Verified Identity Resolution
 ↓
Existing Telegram/Core User
 ↓
CustomerAccount
```

### Guest Failure / Duplicate

باید بررسی شود که:

- Payment شکست‌خورده باعث Purchase معتبر نشود.
- Retry باعث Duplicate Purchase نشود.
- Guest Session به‌صورت نادرست به User دیگری متصل نشود.
- عدم موفقیت Identity Resolution باعث باطل شدن خرید موفق Guest نشود.

**124. Test Strategy — Payment**

Payment باید بر اساس State Machine نهایی تعریف‌شده در Code و Database تست شود.

حداقل Stateهای موردنیاز:

```text
Created
Pending
Processing
Confirmed
Rejected
Refunded
```

تست‌ها باید شامل موارد زیر باشند:

- Valid Transitions
- Invalid Transitions
- Duplicate Webhook
- Repeated Confirmation
- Rejected Payment
- Refunded Payment
- Admin Confirmation
- Order Payment
- Wallet Charge
- Payment Idempotency
- عدم ایجاد Purchase تکراری
- عدم ایجاد Debit تکراری
- تطابق Payment State با Order/Purchase State
- Card-to-Card Receipt Review
- Gateway/Webhook Failure

Payment Test باید اطمینان دهد که Channelها Stateهای مستقل و متناقض برای یک Payment ایجاد نمی‌کنند.

**125. Static Legacy Search**

در
پایان Refactor:

git grep -n -E
"base_price|core_price|sold_price|custom_price|corePrice|soldPrice|sellingPriceForReseller"

نباید
نتیجه‌ای از مفهوم Pricing Legacy باقی بماند.

---

**126. Definition of Done — Core**

Core زمانی
آماده است که:

- User از CustomerAccount      جدا باشد.
- CustomerAccount      Store-scoped باشد.
- StoreContext      تثبیت شده      باشد.
- Wallet      بر اساس User      + StoreContext کار      کند.
- Pricing      فقط سه      مفهوم داشته باشد.
- Purchase      Atomic باشد.
- Payment      State Machine فعال      باشد.
- Price      Snapshot وجود      داشته باشد.
- Reseller      Debt Limit enforce شود.
- Reseller      Double Debit صحیح      باشد.
- Provisioning      State مستقل      باشد.
- Provisioning      Retry حداکثر      3 تلاش داشته باشد.
- Idempotency      فعال باشد.
- Renewal      مستقل      باشد.
- Referral      و      Commission جدا      باشند.
- Commission      Snapshot شود.
- Refund      Commission را      خودکار Reverse نکند.
- Guest بدون Login می‌تواند Checkout را شروع کند.
- Guest Form دارای Email الزامی باشد.
- CustomerAccount اولیه بتواند Unverified/Unlinked باشد.
- Pending Page کاربر را به Login/Register برای تکمیل خرید هدایت کند.
- Purchase نهایی Guest پس از Login/Register تکمیل شود.
- Telegram Linking معتبر بتواند CustomerAccount را به User Canonical متصل کند.

---

**127. Definition of Done — Reseller**

Reseller زمانی
آماده است که:

- User جدید برای      Reseller ایجاد      نشود.
- Reseller      Context داشته      باشد.
- Main      Customer بودن      Reseller حفظ      شود.
- Product      جدید      ایجاد نکند.
- Core      Productها      را ببیند.
- customers_price      تعیین کند.
- Customerهای خودش      را مدیریت کند.
- Wallet      مشتریان      در Context صحیح      مدیریت شود.
- هزینه      تأمین از Main Wallet صاحب Reseller با reseller_price      انجام شود.
- Debt      Limit enforce شود.
- Purchase      Atomic باشد.
- Reseller      Scope enforce شود.
- Bot و      Website از      Core مشترک      استفاده کنند.

---

**128. Definition of Done — Website**

Website زمانی Done محسوب می‌شود که تمام Flowهای اصلی آن به Core متصل و Verification شده باشند و هیچ Business Logic متناقضی در Website وجود نداشته باشد.

### Main Website

حداقل باید شامل:

```text
Home
Products
Product Detail
Checkout
Guest Checkout
Login
Register
Wallet
Orders
Accounts
Renewal
Referral
Commission
Manual Payment
```

باشد.

همچنین:

- Guest و CustomerAccount باید منوی مناسب و متفاوت داشته باشند.
- Guest Checkout بدون Registration قابل اجرا باشد.
- Main Website از Main StoreContext استفاده کند.
- Wallet و Orderهای Main فقط در Context مربوطه نمایش داده شوند.
- Purchase و Payment توسط Core کنترل شوند.
- Renewal، Referral و Commission از Core دریافت شوند.
- اطلاعات مدیریتی Main طبق دسترسی مجاز نمایش داده شود.

### Reseller Website

هر Reseller Website باید Store-specific باشد و حداقل شامل:

```text
Store-specific Context
Store-specific Pricing
Store-specific Customers
Store-specific Orders
Store-specific Payment
Store-specific Branding
```

باشد.

هر Reseller Website باید:

- فقط Context همان Reseller را در جریان جاری خود استفاده کند.
- Customers همان Reseller را نمایش دهد.
- Walletهای Customerهای همان Reseller را طبق مجوز نمایش دهد.
- Productهای مجاز همان Reseller را نمایش دهد.
- Pricing را از Core دریافت کند.
- قابلیت‌های مجاز Reseller مانند Enable/Disable کردن Productها را پشتیبانی کند.
- منوی Reseller با Main متفاوت باشد.
- Branding اختصاصی مانند Name، Logo و Contact Information داشته باشد.
- Business Logic مستقل از Core نداشته باشد.

### Core Integration

Website باید برای Flowهای زیر به‌صورت End-to-End با Core Verification شود:

```text
Guest Checkout
Login
Register
Identity Resolution
CustomerAccount
Wallet
Orders
Payment
Renewal
Referral
Commission
Manual Payment
Provisioning
Refund
```

و در Reseller Website:

```text
Reseller Context
Reseller Customer
Reseller Pricing
Reseller Wallet Scope
Reseller Order
Reseller Payment
Reseller Provisioning
```

تمام عملیات مالی، Pricing، Purchase، Payment، Provisioning، Renewal، Refund و Commission باید توسط Core enforce شوند.

**129. Definition of Done — Production**

قبل از
Production:

Backup DB

Migrations Verified

Data Backfill Verified

Tests Passing

Secrets Rotated

Webhook Secured

Audit Enabled

Payment Tested

Purchase Tested

Provisioning Tested

Retry Tested

Refund Tested

Reseller Debt Tested

Guest Checkout Tested

Rollback Plan Verified

---

**130. Rollback Strategy**

هر
Phase باید
تا حد امکان Rollbackable باشد.

Migrationهای
Destructive باید
با:

Backup/Restore

و:

Verified Recovery Plan

مدیریت
شوند.

خصوصاً:

CustomerAccount Migration

Wallet Migration

Pricing Migration

Payment Changes

Purchase Changes

Provisioning Changes

Website Deployment

در
مورد Migrationهای
Destructive،
صرفاً داشتن down() کافی
نیست و باید Recovery واقعی
از Backup/Restore در محیط مناسب تست و Verification شود.

---

**131. Deployment Order**

1\. Backup DB

2\. Deploy Migration

3\. Run Data Backfill

4\. Validate Counts

5\. Validate Wallets

6\. Validate Orders

7\. Validate Payments

8\. Enable Core

9\. Enable Financial Flow

10\. Enable Bot Integrations

11\. Enable Website

12\. Enable Reseller Website

13\. Monitor

اگر یک
Phase مشکل
داشت، Phase بعدی
فعال نشود.

---

**132. Features خارج از Scope فعلی**

فعلاً خارج از Scope:

```text
Advanced RBAC

Full Financial Reconciliation

Advanced Monitoring

Mobile App

Public API

Language / Multi-language

Currency / Multi-currency

Object Storage

Wallet Transfer

Partial Wallet + Direct Payment

Reseller Product Creation
```

`Language` و `Currency` در نسخه فعلی برای آینده در نظر گرفته می‌شوند و جزء Contract فعلی اجرای Website/Telegram/Core نیستند.

این موارد حذف دائمی نیستند؛ فقط فعلاً پیاده‌سازی نمی‌شوند و در صورت فعال‌شدن باید به‌عنوان Feature/Contract مستقل تعریف و Verification شوند.

**133. Priority Order**

P0

Security / Data Integrity

        ↓

P0

CustomerAccount + StoreContext

        ↓

P0

Wallet + Pricing

        ↓

P0

Payment + Purchase

        ↓

P0

Reseller Financial Flow

        ↓

P0

Provisioning

        ↓

P1

Renewal

        ↓

P1

Referral / Commission / Discount

        ↓

P1

Main Bot + Reseller Bot

        ↓

P1

Main Website

        ↓

P1

Reseller Website

        ↓

P2

Hardening / Monitoring

---

**134. Final Architecture — Main Purchase**

Customer

    │

    ▼

Main Store

    │

    ▼

CustomerAccount

    │

    ▼

Product

    │

    ▼

main_price

    │

    ▼

PurchaseGuard

    │

    ▼

Main Wallet Debit

    │

    ▼

Order

    │

    ▼

Provisioning

    │

    ▼

VPN Account

---

**135. Final Architecture — Reseller Purchase**

Customer

    │

    ▼

Reseller Context

    │

    ▼

CustomerAccount

    │

    ▼

Product

    │

    ├───────────────┐

    │               │

    ▼               ▼

customers_price  
reseller_price

    │               │

    ▼               ▼

Customer Wallet  
Reseller Owner

Reseller Context  Main
Context

    │               │

    └── -X          └── -Y

            │

            ▼

          Order

            │

            ▼

       Provisioning

---

**136. Final Architecture — Wallet**

                        
USER

                          
│

           
┌──────────────┼──────────────┐

            │              │              │

            ▼              ▼              ▼

        Main
Context   Reseller A     Reseller B

            │           Context        Context

            │              │              │

            ▼              ▼              ▼

         Wallet          Wallet         Wallet

هیچ‌کدام
نام فنی متفاوتی ندارند.

همه:

wallet

هستند.

Context مالکیت
و کاربرد را مشخص می‌کند.

---

**137. Final Pricing Contract**

تنها
قیمت‌های معتبر:

main_price

reseller_price

customers_price

**Main**

Customer → main_price

**Reseller Customer**

Customer → customers_price

**Reseller Supply**

Reseller Owner Main Wallet → reseller_price

---

**138. Final Wallet Contract**

Wallet

\=

User

\+

StoreContext

مثال:

User C + Main

→ C Main Wallet

و:

User Ali + Reseller A

→ Ali / A Wallet

---

**139. Final Reseller Financial Contract**

برای:

customers_price = 14

reseller_price = 10

نتیجه:

Customer Wallet / Reseller Context

→ -14

Reseller Owner Wallet / Main Context

→ -10

و:

Profit = 14 - 10

---

**140. Final Identity Contract**

اصل نهایی Identity در Melorin:

```text
Reseller
=
Existing Main User
+
Reseller Context
```

Reseller یک User جدید نیست.

همچنین:

```text
User != CustomerAccount
```

و:

```text
User
+
StoreContext
=
CustomerAccount
```

بنابراین:

- User هویت اصلی سیستم است.
- CustomerAccount عضویت User در یک StoreContext است.
- یک User می‌تواند Customer Main باشد.
- همان User می‌تواند هم‌زمان Customer چند Reseller باشد.
- Reseller همچنان User و Customer در Main باقی می‌ماند.
- ایجاد Reseller نباید User جدید ایجاد کند.

### Guest Identity

Guest در Website یک هویت موقت برای شروع Checkout است.

```text
Guest
=
Temporary Website Checkout Identity
```

Guest می‌تواند بدون Login از Product گزینه «خرید به‌عنوان مهمان» را انتخاب کند.

اما Guest در این معماری Purchase نهایی را بدون Login/Register تکمیل نمی‌کند.

فرم Guest:

```text
name
phone
email  ← required
```

با دریافت Email:

```text
Guest
 ↓
Provisional / Unverified CustomerAccount
```

ایجاد می‌شود.

این CustomerAccount:

- برای StoreContext جاری ایجاد/Resolve می‌شود.
- هنوز Trusted Identity محسوب نمی‌شود.
- صرفاً با Email به User موجود Merge نمی‌شود.
- تا زمان Identity Linking معتبر، وضعیت Unverified/Unlinked دارد.

سپس:

```text
Provisional CustomerAccount
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
```

برای اتصال به User موجود در Core/Telegram، Identity Linking باید معتبر و احراز‌شده باشد.

اگر Telegram Linking موفق باشد:

```text
Provisional CustomerAccount
 ↓
Verified Telegram Linking
 ↓
Existing Core User
 ↓
Trusted CustomerAccount
```

Email به‌تنهایی برای Trusted Identity کافی نیست.


**141. Final Provisioning Contract**

Purchase

 ↓

Financial State

 ↓

Provisioning

Provisioning دارای Lifecycle مستقل است.

در
Failure:

retry

یا:

refund

طبق
Policy.

Retry نباید
Debit دوباره
ایجاد کند.

---

**142. Final Refund Contract**

**Main**

Refund = main_price

**Reseller**

Customer Refund = customers_price

Reseller Refund = reseller_price

و تمام
Refundها از
Snapshot Order استفاده
می‌کنند.

---

**143. Final Commission Contract**

Referral

≠

Commission

Commission:

Rate Snapshot

\+

Amount Snapshot

و:

Purchase Refund

به‌صورت
خودکار Commission را
Reverse نمی‌کند.

---

**144. Final Architectural Rule Set**

**Rule 1**

Reseller = Existing Main User + Reseller Context

**Rule 2**

User != CustomerAccount

**Rule 3**

CustomerAccount = User + StoreContext

**Rule 4**

Wallet = User + StoreContext

**Rule 5**

Direct Main Purchase → main_price

**Rule 6**

Reseller Customer Purchase → customers_price

**Rule 7**

Reseller Supply → reseller_price

**Rule 8**

Customer Wallet → Reseller Context

**Rule 9**

Reseller Owner Wallet → Main Context

**Rule 10**

`customers_price` هرگز برای Debit Wallet صاحب Reseller در Main استفاده نمی‌شود.

**Rule 11**

`main_price` برای خرید Customer از Reseller استفاده نمی‌شود.

**Rule 12**

`reseller_price` قیمت فروش Reseller به Customer نیست.

**Rule 13**

یک User می‌تواند Customer چند Reseller باشد.

**Rule 14**

Reseller همچنان Customer Main باقی می‌ماند.

**Rule 15**

Reseller User جدید نیست.

**Rule 16**

Walletها مستقل و Context-scoped هستند.

**Rule 17**

هر Debit/Credit باید Ledger داشته باشد.

**Rule 18**

Purchase مالی باید Atomic باشد.

**Rule 19**

Purchase باید Idempotent باشد.

**Rule 20**

Retry Provisioning نباید Debit دوباره ایجاد کند.

**Rule 21**

Refund باید بر اساس Price Snapshot انجام شود.

**Rule 22**

Commission و Referral جدا هستند.

**Rule 23**

Commission بعد از Purchase Refund به‌صورت خودکار Reverse نمی‌شود.

**Rule 24**

Guest بدون Login می‌تواند Checkout را شروع کند.

**Rule 25**

Guest Form شامل name، phone و email است و Email الزامی است.

**Rule 26**

با دریافت Email، یک CustomerAccount اولیه/Provisional ایجاد یا Resolve می‌شود.

**Rule 27**

CustomerAccount اولیه تا زمان Identity Linking معتبر، Unverified/Unlinked است.

**Rule 28**

Email به‌تنهایی Proof of Identity یا مجوز Merge با User موجود نیست.

**Rule 29**

Pending Page باید کاربر را به Login/Register برای تکمیل همان خرید هدایت کند.

**Rule 30**

Purchase نهایی Guest پس از Login/Register و اتصال CustomerAccount به User معتبر تکمیل می‌شود.

**Rule 31**

Telegram Linking معتبر می‌تواند CustomerAccount اولیه را به User Canonical موجود در Core متصل کند.

**Rule 32**

Merge بین Identityها فقط با مکانیزم معتبر و احراز‌شده مجاز است.

**Rule 33**

Channelها نباید Business Logic مستقل و متناقض با Core داشته باشند.

**Rule 34**

Website و Telegram باید از Core به‌عنوان مرجع نهایی Business Logic استفاده کنند.

**145. نهایی‌ترین Data Flow**

### Main Purchase

```text
User
 ↓
Main CustomerAccount
 ↓
Main StoreContext
 ↓
Product
 ↓
main_price
 ↓
Main Wallet
 ↓
Order
 ↓
Provisioning
 ↓
VPN Account
```

### Reseller Customer Purchase

```text
User
 ↓
Reseller CustomerAccount
 ↓
Reseller StoreContext
 ↓
Product
 ↓
customers_price
 ↓
Customer Wallet / Reseller Context
```

هم‌زمان:

```text
Reseller Owner User
 ↓
Main StoreContext
 ↓
reseller_price
 ↓
Owner Main Wallet
```

سپس:

```text
Order
 ↓
Provisioning
 ↓
VPN Account
```

### Guest Checkout / Purchase

Guest بدون Login فقط می‌تواند Checkout را شروع کند:

```text
Guest
 ↓
Website
 ↓
Product
 ↓
Buy as Guest
 ↓
Guest Form
 ├── name
 ├── phone
 └── email (required)
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
 ↓
VPN Account
```

Email به‌تنهایی Trusted Identity ایجاد نمی‌کند.

اگر Telegram Linking معتبر انجام شود:

```text
Provisional CustomerAccount
 ↓
Verified Telegram Linking
 ↓
Existing Core User
 ↓
Trusted CustomerAccount
```

Purchase نهایی Guest بدون Login/Register تکمیل نمی‌شود.

**146. اصل نهایی و غیرقابل تغییر معماری**

**Reseller در Melorin یک User جدید نیست؛ Reseller یک User موجود Main است که یک Reseller Context دریافت می‌کند. همان User همچنان Customer Main باقی می‌ماند و می‌تواند هم‌زمان در Main و چند Reseller Context فعالیت کند.**

**CustomerAccount نشان‌دهنده عضویت یک User در یک Store است و Wallet نشان‌دهنده موجودی همان User در یک StoreContext است.**

**سه و فقط سه مفهوم قیمت در سیستم وجود دارد:**

```text
main_price
reseller_price
customers_price
```

**در خرید مستقیم Main،** `main_price` **از Wallet User در Main Context کسر می‌شود.**

**در خرید Customer از Reseller،** `customers_price` **از Wallet Customer در Reseller Context و** `reseller_price` **از Wallet صاحب Reseller در Main Context کسر می‌شود.**

**Wallet موجودیت مستقلی با نام عمومی `wallet` است و Context آن با `user_id`، `store_type` و `reseller_id` مشخص می‌شود.**

**تمام قوانین مالی، Pricing، Scope، Purchase، Payment، Provisioning و Renewal باید در Core enforce شوند و هیچ Channel نباید Business Logic مستقل و متناقض با Core داشته باشد.**

### Guest

**Guest یک هویت موقت Website است و برای خرید الزاماً نیاز به User یا CustomerAccount ندارد.**

بنابراین:

```text
Guest
+
Product
+
Checkout
+
Payment
=
Purchase
```

مجاز است.

**Guest می‌تواند بدون Registration و بدون CustomerAccount خرید خود را انجام دهد.**

پس از Purchase موفق، سیستم باید برای تبدیل یا اتصال Guest به User و سپس ایجاد یا Resolve کردن CustomerAccount مربوط به StoreContext تلاش کند:

```text
Guest
 ↓
Successful Purchase
 ↓
Identity Resolution
 ↓
Existing User / New User
 ↓
CustomerAccount
```

اگر Guest همان User موجود در Core/Telegram تشخیص داده شود، باید طبق قواعد Identity Resolution به همان User متصل شود.

اگر Identity Resolution موفق نشود، Purchase موفق Guest و Provisioning مربوط به آن نباید صرفاً به همین دلیل باطل یا متوقف شود.

Guest Identity نباید بدون احراز معتبر با User دیگری Merge شود.

این قواعد باید در Core enforce شوند و Website و Telegram صرفاً Channelهای ارائه و اجرای Flow باشند.

### Canonical Architecture Contract

این سند مرجع اصلی معماری است و وضعیت هر قابلیت باید در Contract Verification Matrix با یکی از وضعیت‌های زیر مشخص شود:

```text
SPECIFIED
IMPLEMENTED
TESTED
PRODUCTION VERIFIED
```

این Statusها جایگزین Contract معماری نیستند؛ بلکه وضعیت تحقق آن را مشخص می‌کنند.
