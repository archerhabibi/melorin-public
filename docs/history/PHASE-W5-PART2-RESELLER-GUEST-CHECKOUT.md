# فاز W5 (بخش دوم) - Guest Checkout نماینده (v3.2.15)

مرجع: ROADMAP-WEBSITE-v1.md فاز W5 آیتم 4 + فاز W3 + docs/history/PHASE-W5-PART1-RESELLER-STORE.md
(بخش «عمدا در این پچ نیست» نفر 3). طبق درخواست شما، این بخش را (به‌عنوان مالک
Guest، نفر 1) خودم انجام دادم.

## نتیجه‌ی بررسی: کد جدیدی لازم نبود، تست لازم بود

نفر 3 پیش‌بینی کرده بود «چون کنترلرهای Guest از نوع Shared خواهند بود، زیر
/store/{slug} هم خودکار کار می‌کند». من هر حلقه‌ی زنجیره را در کد بررسی کردم:

| حلقه | نتیجه |
|---|---|
| مسیرها | داخل $registerSharedRoutes (پچ 3.2.3/3.2.4) هستند، پس هر دو نسخه‌ی website.* و website.store.* ثبت شده‌اند |
| نمایش محصول/قیمت | WebsiteCatalogFacade::findVisibleProduct/displayPrice - فقط محصول فعال‌شده‌ی نماینده، با customers_price |
| توکن | GuestCheckoutService::start reseller_id را ثبت می‌کند؛ findActive روی reseller_id فیلتر می‌کند (توکن Main در فروشگاه نماینده و بالعکس معتبر نیست) |
| Redirectها | همه با ResolvesWebsiteRouteNames (پچ 3.2.4) - در Context نماینده به website.store.* می‌روند |
| ساخت User و Login | Context-independent است؛ CustomerAccount نماینده Lazy توسط EnsureCustomerAccountResolved ساخته می‌شود |
| ادامه‌ی خرید | همان CheckoutController تست‌شده (Debit دوگانه توسط PurchaseService در Core) |
| Claim حساب | routeهای identity.complete-profile.* در همان گروه shared |

هیچ باگ یا تفاوتی پیدا نشد که نیاز به تغییر کد داشته باشد؛ پس عمدا کد جدیدی
اضافه نکردم (تغییر چیزی که کار می‌کند فقط ریسک است). آنچه نبود «اثبات» بود.

## چه چیزی اضافه شد

tests/Feature/Website/ResellerGuestCheckoutTest.php (هفت تست):
- دکمه‌ی مهمان در صفحه‌ی محصول نماینده به مسیر website.store.* اشاره می‌کند.
- فرم مهمان فقط customers_price را نشان می‌دهد (نه main_price، نه reseller_price).
- توکن Reseller-scoped است: Redirectها داخل فروشگاه می‌مانند، توکن در Main و در
  فروشگاه نماینده‌ی دیگر رد می‌شود.
- محصولی که آن نماینده فعال نکرده، قابل شروع Guest Checkout نیست (404، رکوردی ساخته نمی‌شود).
- شماره‌ی از قبل ثبت‌شده به website.store.login می‌رود (نه login فروشگاه اصلی)، بدون merge خودکار.
- خرید کامل: مهمان -> User ناقص -> Checkout نماینده -> Debit کیف‌پول مشتری (customers_price)
  و سفارش با reseller_id و Price Snapshot درست -> صفحه‌ی تکمیل حساب زیر همان فروشگاه.
  (Debit دوم کیف‌پول Main صاحب نماینده در ResellerCommerceFlowTest نفر 3 با جزئیات سنجیده می‌شود.)

## اثر روی Verification Matrix

ردیف Guest Checkout حالا Main و Reseller را پوشش می‌دهد؛ E2E «Guest نماینده» اضافه شد.

## محدودیت‌ها

- بدون PHP نوشته شده؛ اجرا: php artisan test --filter=ResellerGuestCheckoutTest
- Telegram-linking برای نماینده هنوز نیست (ستون bot_username ندارد) - تصمیم پچ 3.2.5 بدون تغییر.
- یادداشت نفر 3 درباره‌ی EnsureCustomerAccountResolved (عضویت خودکار) هنوز به Review مستقل امنیتی نیاز دارد؛ این پچ آن را تغییر نداد.
