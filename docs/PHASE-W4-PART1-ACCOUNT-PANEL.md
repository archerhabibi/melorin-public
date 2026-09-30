# فاز W4 (بخش اول) — پنل کاربری: Wallet + Orders + Accounts (v3.2.11)

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W4 (نفر ۲، مالکیت انحصاری
`Controllers/Account/` و `views/website/account/`) + بندهای ۳۰، ۳۱،
۳۲، ۳۳، ۵۰، ۵۲، ۵۶ سند `Melorin_Website_Architecture_Subdocument_v1_1.md`.

پچ متناظر: `melorin-website-w4-part1.patch`. طبق مرز پچی که خودِ

> **یادداشت ادغام**: این پچ اصلاً به‌عنوان نسخه‌ی ۳.۲.۴ روی مبنای
> ۳.۲.۳ نوشته شده بود. تا رسیدنش، پچ‌های ۳.۲.۴ تا ۳.۲.۱۰ (نفر ۱: بقیه‌ی
> W3، نفر ۴: کل W6) منتشر شده بودند. بدون هیچ تغییر منطقی، فقط شماره
> به ۳.۲.۱۱ (عدد واقعی بعدی) تغییر کرد؛ `VERSION` و `routes/website.php`
> با دست ادغام شدند (این دو فایل مشترک هستند)، بقیه‌ی فایل‌ها بدون
> تغییر اعمال شدند — هیچ تداخل واقعی‌ای در کد پیدا نشد چون
> `Controllers/Account/` کاملاً از پوشه‌های نفر ۱ و نفر ۴ جداست.
Roadmap برای فاز W4 پیشنهاد داده («لایه‌بندی پچ پیشنهادی»): فقط
آیتم‌های **۱ تا ۳** (Wallet، Orders، Accounts — «همه صرفاً نمایشی»)،
نه کل فاز.

## چرا فقط آیتم ۱–۳، نه کل فاز W4

Roadmap صراحتاً می‌گوید آیتم‌های ۴ تا ۷ (Renewal، Referral/Commission،
Refund UI، Retry UI) «همه به یک تصمیم Core نیاز دارند، نه فقط
نمایش»:

- **Renewal (بند ۴)**: باید تصمیم بگیریم دکمه‌ی «تمدید» از Website چه
  خطاهایی از `RenewalService::renew()` را چطور به فارسیِ قابل‌فهم برای
  کاربر ترجمه کند (بند ۵۶: نگاشت خطا)، و آیا نتیجه‌ی همزمان
  (synchronous) نمایش داده شود یا صفحه باید Redirect کند تا Idempotency
  Key تکراری نشود.
- **Refund UI / Retry UI (بند ۶، ۷)**: طبق بند ۵۰ سند («دروازه‌ی واقعی
  سمت Core است، دکمه صرفاً UX است»)، باید مشخص شود اصلاً کاربر عادی
  (نه ادمین) اجازه‌ی **درخواست** بازگشت وجه/تلاش مجدد را دارد یا این دو
  کاملاً Admin-only می‌مانند و Website فقط **نتیجه**‌شان را نمایش
  می‌دهد. این پرسش را نباید ضمنی و در دل این پچ جواب داد.
- **Referral/Commission (بند ۵)**: نمایشی است، اما به یک تصمیم کوچک‌تر
  نیاز دارد (کدام فیلدهای `Commission`/رابطه‌ی معرف قرار است نمایش داده
  شوند) که بهتر است با آیتم‌های هم‌خانواده‌اش (۴، ۶، ۷) در یک پچ واحد
  حل شود، نه جدا.

پس این پچ Wallet/Orders/Accounts را تا «نمایش کامل و ایزوله‌شده» می‌برد؛
آیتم‌های ۴ تا ۷ به‌صراحت به پچ بعدی موکول شدند.

## چه چیزی ساخته شد

- **`WebsiteWalletFacade::transactions()`** — Adapter نازک روی همان
  `WalletService::walletForContext()` که `balance()` هم استفاده
  می‌کند (بند ۳۰، ۳۱: بدون کوئری مستقل، بدون منطق تجاری در Website).
- **`Account\WalletController::show()`** — موجودی + گردش حساب
  صفحه‌بندی‌شده. دکمه‌ی «شارژ» فقط لینک به `wallet.charge.show` (فاز
  W2) است، نه فرم شارژ دوباره‌ساخته‌شده.
- **`Account\OrdersController::index()`** — فهرست کامل سفارش‌های
  کاربر، دقیقاً هم‌الگو با مالکیتی که `Shared\OrderController::show()`
  (فاز W2) از قبل برای یک سفارش پیاده کرده بود: هم `customer_account_id`
  (که خودش توسط میان‌افزار `store.customer` Context-scoped شده) و هم
  `reseller_id` صریحاً چک می‌شود (بند ۳۲: «فقط Orderهای مجاز Context
  جاری»).
- **`Account\AccountsController::index()` و `show()`** — فهرست
  اکانت‌های VPN + جزئیات یک اکانت. طبق بند ۵۲ («داده‌ی حساس نباید
  بدون دلیل به Client فرستاده شود»)، جزئیات محدود به `subscription_url`
  است، نه محتوای خامِ رمزنگاری‌شده‌ی `config_data`.
- **`WalletTransaction::typeLabels()`** — برچسب فارسیِ نوع تراکنش،
  هم‌الگو با `Order::statusLabels()` موجود؛ فقط نمایشی، منطق تجاری
  نیست (بند ۵۶).
- یک partial محلی (`views/website/account/_nav.blade.php`) به‌جای
  ویرایش `layouts/app.blade.php` — طبق بخش ۱۰ Roadmap («نقاط
  اشتراکی»)، آن Layout مالکیت نفر ۳ است و بقیه فقط Extend می‌کنند.

## Isolation و مالکیت — دقیقاً کجا تست شده

- `orders_index_never_leaks_across_store_context` — یک User که هم در
  Main هم در یک Reseller مشتری است، در هر Context فقط سفارش‌های همان
  Context را می‌بیند (بند ۳۱، ۳۲).
- `a_customer_cannot_view_another_customers_account_detail` — دسترسی
  مستقیم با شناسه‌ی عددی به `/accounts/{id}` برای اکانتِ کاربر دیگر
  ۴۰۴ می‌گیرد، نه ۴۰۳ (تا وجود/عدم‌وجود رکورد فاش نشود).
- `wallet_balance_of_another_user_is_never_shown` — یک محک منفیِ ساده
  که وابستگی به `auth()->user()` (نه ورودی قابل‌دستکاری) را تثبیت
  می‌کند.

## آیتم‌های به‌تعویق‌افتاده (پچ بعدی، طبق تصمیم صریح Roadmap)

۴. Renewal — دکمه‌ی «تمدید» + نگاشت خطای `RenewalService`.
۵. Referral/Commission — نمایش زیرمجموعه‌ها و کمیسیون‌ها.
۶. Refund UI — نمایش وضعیت Refund؛ نیاز به تصمیم «درخواست کاربر یا
   فقط نمایش نتیجه‌ی تصمیم ادمین؟» قبل از پیاده‌سازی.
۷. Retry UI — همان‌طور؛ نیاز به تصمیم مشابه برای Retry (اصلاً
   کاربر عادی حق دیدن/زدن این دکمه را دارد یا Admin-only؟).

## اجرا

```
php artisan test --filter=AccountPanelTest
```

بدون Migration جدید — این پچ فقط کد/View/Route/تست است.
