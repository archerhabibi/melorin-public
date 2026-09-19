# فاز ۱۲ — هماهنگی نهایی Telegram / Filament با مدل سه‌قیمتی

مرجع سند معماری: بند ۱۲، ۱۳، ۴۹، ۵۷ و ۵۹ (مرحله‌ی ۱۲).

## نتیجه‌ی Audit
جستجوی Legacy (`base_price|core_price|sold_price|custom_price|corePrice|soldPrice|sellingPriceForReseller`) در
`app/ resources/ routes/ config/ tests/ database/(غیر از migrationهای تاریخی)` نتیجه‌ی صفر داشت — فقط ARCHITECTURE.md
بند ۷ هنوز `core_price/sold_price` را به‌عنوان مفهوم قیمت آموزش می‌داد. اما «مفهوم قیمتِ خارج از سه نام» هنوز در
UI و Handlerها زنده بود و یک باگ واقعی هم پیدا شد.

## باگ واقعی
**صفحه‌ی «گزارشات و آمار» با هر بار باز شدن ErrorException می‌داد.**
`Reports::getSummary()` برای شمارش پرداخت‌های در انتظار از `$from` و `$to` استفاده می‌کرد که در آن متد تعریف نشده
بودند (فقط داخل `applyPreset()` وجود داشتند). اصلاح: `$this->from()` / `$this->to()`.

## تغییرات
### نام‌گذاری قیمت
| قبل | بعد |
|---|---|
| `ResellerPricingService::setSellingPrice()` | `setCustomersPrice()` |
| کلید فرم پنل نماینده `selling_price` | `customers_price` |
| `$sellingPrice` در Keyboardهای ربات نماینده | `$customersPrice` |
| کلیدهای گزارش `base_cost` / `gross_margin` | `supply_cost` / `reseller_margin` |
| متغیر/آرگومان تست `basePrice`, `sellingPrice` | `mainPrice`, `customersPrice` |
| کامنت `Customers_price` در Product | `customers_price` |

### ربات اصلی
سه فایل (`Keyboards`, `BuyAccountHandler`, `AccountsHandler`) مستقیم `$product->main_price` می‌خواندند؛
حالا از API سند (بند ۱۳) یعنی `$product->mainPrice()` استفاده می‌کنند.

### برچسب‌های پنل
- پنل ادمین (محصولات): «قیمت پایه» ← «قیمت فروش مستقیم — main_price»، «قیمت نمایندگان» ← «قیمت تأمین برای نماینده — reseller_price».
- پنل نماینده (محصولات و سفارش‌ها): «هزینه‌ی پایه» ← «هزینه‌ی تأمین (reseller_price)»، «قیمت فروش» ← «(customers_price)».
- پنل نماینده (سفارش‌ها): وضعیت‌های `provisioning`/`provision_failed` از فاز ۱۱ اینجا هم برچسب و رنگ دارند.

### Wallet (بند ۴۹)
ثابت‌های حالت مکالمه‌ی ربات نماینده `RESELLER_WALLET_*` (مقدار `reseller_wallet:*`) به
`OWNER_MAIN_WALLET_TOPUP_*` (مقدار `owner_main_wallet_topup:*`) تغییر کرد: این جریان، شارژ Wallet صاحبِ نماینده
در Main Context است، نه یک موجودیت جدا به نام reseller_wallet. اثر جانبی: کسی که دقیقاً در لحظه‌ی دیپلوی وسط
همین جریان شارژ باشد باید دوباره از منو شروع کند.

### کد مرده‌ی `payments.purpose = 'order'`
هیچ کدی Payment با این هدف نمی‌ساخت (خرید مستقیماً از Wallet کسر می‌شود، بند ۱۶). `PaymentService::initiate()`
حالا فقط `wallet_charge` می‌پذیرد و کامنت‌های گمراه‌کننده‌ی `PaymentConfirmed` و Listener اصلاح شد.
مقدار `order` در enum دیتابیس عمداً دست‌نخورده ماند (تغییر enum در MySQL/SQLite ریسک بی‌فایده است).

### ARCHITECTURE.md بند ۷
به سه قیمت نهایی و `reseller_profit` بازنویسی شد.

## تست‌ها
`tests/Feature/Architecture/PricingNamingAndReportsTest.php`:
1. اسکن دائمی Legacy Nameها (بند ۵۷) — از این پس هر بازگشت نام قدیمی، تست را قرمز می‌کند.
2. `Reports::getSummary()` بدون خطا و با مقادیر درست سه‌قیمتی (revenue 26، supply_cost 22، margin 4).
3. `initiate()` با purpose=`order` رد می‌شود.
تست‌های موجود با نام‌های جدید به‌روز شدند (کلید `customers_price` در `ResellerPanelIsolationTest` و ...).

## آنچه عمداً دست نخورد
- ستون `resellers.min_sale_price_rule` و کلاس/فایل `ResellerWalletHandler`: تغییر نامشان Migration و تغییر گسترده می‌خواهد و
  در فهرست ممنوعه‌ی سند نیست؛ در صورت نیاز فاز جدا.
- ساختار Wallet (`wallet = user + StoreContext`, بندهای ۲۱–۲۸) مطابق سند نیست: در پروژه Wallet روی
  `CustomerAccount`/`Reseller` است. این تصمیم معماریِ فازهای قبلی است و در این فاز بازنویسی نشد.
