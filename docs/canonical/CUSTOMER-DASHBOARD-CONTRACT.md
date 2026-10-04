# Customer Dashboard Contract (B3.1)

> نسخه 1.0 · Parent: Master 2.8 / Website 1.9 · وضعیت: CANONICAL
> دامنه: صفحه‌ی `GET /dashboard` (`website.dashboard` / `website.store.dashboard`) — نقطه‌ی ورود پنل مشتری.

## D1. اصل

داشبورد **فقط‌خواندنی** است و هیچ منطق کسب‌وکاری در Website ندارد. منطق در Core است
(`Services\Core\Customer\CustomerDashboardService`)؛ Website فقط Adapter است
(`WebsiteDashboardFacade` + `DashboardController`) و فقط «هدف» اعلان را به URL تبدیل می‌کند.
ربات تلگرام در آینده (T3) می‌تواند از همان سرویس بخواند.

## D2. محتوا

| بخش | منبع | Context |
|---|---|---|
| سرویس‌های فعال (شمارنده + ۴ سرویسِ نزدیک‌ترین انقضا، با مصرف حجم و روز باقی‌مانده) | `Account` با `customer_account_id` همان عضویت | Context-isolated |
| رو‌به‌انقضا (≤ ۷ روز)، منقضی‌شده | Scopeهای `Account::activeNow()` / `lapsed()` | همان |
| کیف‌پول (موجودی + ۵ تراکنش آخر) | `Wallet` با `scope_key` همین Context | همان |
| اعلان‌ها | مشتق از وضعیت زنده (D3) | همان |

«فعال» = `status=active` و (`expires_at` خالی یا آینده). «منقضی» = `status=expired` یا `active` با `expires_at` گذشته.

## D3. اعلان‌ها (بدون ذخیره)

اعلان‌ها **ردیف DB نیستند**؛ از وضعیت Core مشتق می‌شوند و با اصلاح وضعیت (تمدید، شارژ، تأیید رسید) خودبه‌خود حذف می‌شوند. وضعیت «خوانده/نخوانده» وجود ندارد. حداکثر ۶ مورد، مرتب‌شده‌ی خطر → هشدار → اطلاع.

| کلید | شرط | لحن |
|---|---|---|
| `service.expired.{id}` | منقضی در ۳۰ روز اخیر | danger |
| `service.expiring.{id}` | فعال و انقضا ≤ ۷ روز | warning |
| `service.traffic.{id}` | مصرف ≥ ۹۰٪ (۱۰۰٪ ⇒ danger) | warning/danger |
| `order.attention.{id}` | سفارش `provision_failed` همین عضویت+Context | info |
| `payment.pending` | شارژ `wallet_charge` در انتظار (`user_id` + `reseller_id`)؛ تعریف «در انتظار» از `WalletCenterService` (B3.3 — W5.1؛ درگاهِ رهاشده > ۲۴ ساعت شمرده نمی‌شود) | info |
| `ticket.answered` | تیکت `answered` در همین Context (B3.4 — T6)؛ یک تیکت ⇒ لینک مستقیم، چند تیکت ⇒ فهرست | info |
| `wallet.empty` | موجودی ≤ ۰ و سرویس رو‌به‌انقضا/تازه‌منقضی | info |

مرکز اعلان ماندگار (ذخیره، خوانده‌شدن، Push/Email) **خارج از B3.1** است و در صورت نیاز Contract جدا می‌گیرد.

## D4. قواعد ایمنی

- **D4.1** باز کردن صفحه چیزی نمی‌سازد: نه `CustomerAccount` (قاعده‌ی Lazy) و نه `Wallet` خالی (برخلاف `WalletService::balanceIn`).
- **D4.2** کاربر بدون عضویت در این فروشگاه داشبورد خالی می‌بیند (نه 404).
- **D4.3** هیچ اکشن Refund/Retry برای مشتری نیست؛ سفارش ناموفق فقط «در حال رسیدگی» اعلان می‌شود (Admin-only).
- **D4.4** هیچ داده‌ی حساس اتصال (`config_data`، `subscription_url`) در داشبورد نمایش داده نمی‌شود؛ فقط لینک به صفحه‌ی اکانت.
- **D4.5** Core هیچ URL/route نمی‌شناسد؛ `DashboardNotice` فقط `targetType` + `targetId` دارد.

## D5. ناوبری

- «داشبورد» اولین آیتم منوی پنل است و ریشه‌ی Breadcrumb «حساب من» (`_nav.blade.php`).
- لینک نام کاربر در Header به داشبورد می‌رود (قبلاً به Wallet).
- مسیر بعد از ورود تغییر نکرده است (`home`/`intended`).

## D6. Out of Scope

Profile Center (انجام شد در B3.5؛ `CUSTOMER-PROFILE-CONTRACT.md`)، Ticket Center (انجام شد در B3.4؛ `CUSTOMER-TICKETS-CONTRACT.md`)، Service Management (انجام شد در B3.2؛ `CUSTOMER-SERVICES-CONTRACT.md`؛ ارتقا عمداً نیست)، Wallet Center (انجام شد در B3.3؛ `CUSTOMER-WALLET-CONTRACT.md`)، مرکز اعلان ماندگار، نمودار مصرف.
