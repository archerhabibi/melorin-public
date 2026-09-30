# فاز W5 (نفر ۳) — Reseller Website — بخش اول

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W5، بند ۴۳، ۴۶، ۴۷، ۴۹، ۶۲، ۶۶، ۷۱ زیرسند Website.
نقش: **نفر ۳ — فروشگاه نماینده** (مالک: `Controllers/Reseller/`، `views/website/reseller/`، گروه Route نماینده، و
`views/website/layouts/`).

> ⚠️ **این پچ بدون اجرای PHP نوشته شده** (محیط نویسنده PHP ندارد) — دقیقاً مثل پچ‌های W2/W3 قبلی.
> `php artisan migrate && php artisan test` را حتماً اجرا کنید و خروجی را بفرستید؛ طبق درسِ پچ 3.1.9،
> تستی که هرگز اجرا نشده تضمینی نیست.

## Audit پیش از ساخت (آنچه از قبل درست بود)
کنترلرها و Facadeهای مشترک W2 از ابتدا Context-aware نوشته شده‌اند (`StoreContext` از Middleware `ResolveStoreContext`
تزریق می‌شود، `WebsiteCatalogFacade` از `ResellerPricingService::isSellable` و `customersPrice()` استفاده می‌کند،
`WebsitePurchaseFacade` مستقیماً `PurchaseService::purchase(store: …)` را صدا می‌زند که خودش Double-Debit را انجام می‌دهد).
یعنی **آیتم ۱ (Route/Slug) و بخش «Commerce» آیتم ۴ نیاز به کنترلر جدید نداشت** — و همین درست است، چون بخش ۹.۱ Roadmap
صراحتاً «Controllerها بین دو Context تکرار نمی‌شوند» می‌گوید.

آنچه **نبود**:
1. هیچ تستی Context نماینده را در Checkout/Order نمی‌سنجید (`CheckoutFlowTest`/`WalletChargeFlowTest` صفر ارجاع به Reseller).
2. Branding (بند ۴۶): Layout فقط `StoreContext::label()` (= «نمایندگی {slug}») نشان می‌داد و رنگ ثابت `#4f46e5` بود؛ خودِ Layout
   این را با یک TODO مستند کرده بود.
3. منوی اختصاصی نماینده (بند ۴۷) و مدیریتِ سبک (بند ۴۹).

## تغییرات

### ۱) Branding (آیتم ۲ — بند ۴۶)
- Migration جدید `reseller_website_settings` (جدولِ جدا، هم‌الگو با `reseller_bot_settings`): `display_name`، `logo_path`،
  `brand_color`، `contact_phone`، `contact_email`، `about_text`.
- مدل `ResellerWebsiteSetting` با `brandingFor(?Reseller)` — **تنها منبع** برندینگِ نهایی (Layout و فرم ویرایش هر دو از همین
  می‌خوانند). بدون `firstOrCreate` (خواندنِ برندینگ در هر بازدید نباید ردیف بنویسد). نامِ پیش‌فرض همان `StoreContext::label()`
  قبلی است تا نمایندهٔ بدون تنظیمات دقیقاً مثل قبل دیده شود. رنگ فقط hex شش‌رقمی پذیرفته می‌شود (چون داخل `<style>` چاپ می‌شود).
- Layout (`views/website/layouts/app.blade.php`): `--brand` پویا، لوگو، نام نمایشی، اطلاعات تماس در Footer. برای Main هیچ‌چیز عوض نشد.

### ۲) منوی اختصاصی نماینده (آیتم ۳ — بند ۴۷)
لینک‌های «مشتریان / محصولات / تنظیمات فروشگاه» فقط برای ادمین/مالکِ **همین** فروشگاه در Header دیده می‌شود
(`ResellerService::isAdminOf` — همان دروازه‌ی `canAccessTenant` پنل Filament).

### ۳) Reseller Management سبک (آیتم ۵ — بند ۴۹)
`Controllers/Reseller/ManageController` زیر `/store/{slug}/manage/*` (فقط برای Reseller ثبت می‌شود، نه Main):
| مسیر | کار |
|---|---|
| `customers` | فهرست مشتریان همین فروشگاه + موجودی کیف‌پول **در Context همین نماینده** (`WalletService::balanceIn`) — فقط‌خواندنی |
| `products` | محصولات مجاز (همان Queryِ Filament) با `reseller_price`، فرم `customers_price`، فعال/غیرفعال |
| `products/{p}/price` | `ResellerPricingService::setCustomersPrice` — خطای قواعد مرکزی (کف/سقف/سود) عیناً نشان داده می‌شود |
| `products/{p}/enable` / `disable` | با قیمتِ ذخیره‌شده‌ی قبلی؛ اگر آن قیمت با قواعد امروز مجاز نباشد، بی‌صدا فعال نمی‌شود |
| `branding` | مشاهده/ویرایش برندینگ + آپلود لوگو (دیسک `public`، لوگوی قبلی پاک می‌شود) |

**هیچ Business Rule‌ای در کنترلر نیست** («Website فقط UI است»): Audit، اعتبارسنجی قیمت و Scope همه از Core می‌آید.
مجوز داخل خودِ کنترلر است (نه Middleware): این چک ذاتاً به «نمایندهٔ همین Request» وابسته است و جای دیگری قابل‌استفاده‌ی مجدد نیست.

### باگی که هنگام ساخت پیدا و رفع شد
`Product::customersPrice()` برای ردیفِ **غیرفعال** `null` برمی‌گرداند. اگر صفحه‌ی محصولات از آن استفاده می‌کرد، محصولی که
نماینده موقتاً غیرفعال کرده بود قیمتِ قبلی‌اش را نشان نمی‌داد و دکمه‌ی «فعال کردن» **هرگز** ظاهر نمی‌شد. صفحه حالا قیمتِ خامِ
ذخیره‌شده را می‌خواند (تست رگرسیون دارد).

## تست‌ها (۳ فایل جدید در `tests/Feature/Website/`)
- `ResellerCommerceFlowTest` — کاتالوگ با `customers_price` (نه main/reseller_price)، **Double-Debit** (کیف‌پول مشتری در Context
  نماینده = `customers_price` و کیف‌پول Main صاحبِ نماینده = `reseller_price`)، استقلال کیف‌پول Main مشتری، Order Scope
  (Main↔Reseller و Reseller A↔B)، محصولِ فعال‌نشده حتی با درخواست دست‌ساز، کیف‌پولِ تأمینِ خالی، نمایندهٔ غیرفعال/ناشناس ۴۰۴،
  Idempotency.
- `ResellerBrandingTest` — fallback، نمایش برندینگ، عدم نشتِ برندینگ بین نمایندگان، `noindex` فقط برای نماینده، نمایش منو فقط
  برای ادمین همان فروشگاه، آپلود/جایگزینی لوگو، رنگِ نامعتبر، عدم وجودِ مسیر مدیریت برای Main.
- `ResellerManagementTest` — Scope مشتریان و کیف‌پول، قواعدِ قیمتِ Core، فعال/غیرفعال با حفظ قیمت، ادمینِ نمایندهٔ دیگر ۴۰۳،
  مشتری/مهمان بی‌دسترسی، رگرسیون «قیمت محصول غیرفعال».

## عمداً در این پچ نیست
- **آیتم ۴ — بخش Guest Checkout نماینده:** وابسته به کار «نفر ۱» (W3 ساده‌شده) است که هنوز در کد نیست (`3.2.3` هنوز طرح قدیمیِ
  Guest Token را دارد و خرید مهمان را تا تکمیل نمی‌برد). چون کنترلرهای Guest از نوع `Shared` خواهند بود، همین‌که نفر ۱ آن را
  بسازد زیر `/store/{slug}` هم خودکار کار می‌کند؛ نیازی به کپیِ نماینده‌ایِ جدا نیست.
- تغییر `EnsureCustomerAccountResolved` (Middleware مشترک W1): هر کاربر واردشده با بازدید از `/store/{slug}` **خودکار** عضو آن
  نماینده می‌شود (برخلاف ربات که عضویتِ قبلی می‌طلبد). ممکن است عمدی و مطابق UX فروشگاهی باشد، ولی چون به Scope امنیتی
  (بند ۵۱، Customer Scope) برمی‌گردد و در مالکیت من نیست، برای **Review نفر ۴ (امنیت)** ثبت می‌شود، نه تغییر یک‌جانبه.
- مدیریتِ Category (فعال/غیرفعال‌کردنِ کل دسته) در وب: در Roadmap فقط «Enable/Disable Product» آمده؛ Category در Filament می‌ماند.

## اجرا
```
php artisan migrate
php artisan test
```
اگر برای آپلود لوگو `php artisan storage:link` هنوز اجرا نشده، برای دیده‌شدن لوگو روی سرور اجرا شود (دیسک `public`).
