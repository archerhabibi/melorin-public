# تصمیم صاحب پروژه: CustomerAccount فقط در لحظه‌ی خرید (v3.2.17)

مرجع: docs/history/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md (تحلیل نفر ۳) +
دستور صریح صاحب پروژه در همین گفتگو: **«تا خرید انجام نشود نباید
CustomerAccount جدید بسازد.»**

این پچ همان یافته‌ای را که در پایان بازبینی کامل Roadmap گزارش شد رفع
می‌کند: `EnsureCustomerAccountResolved` روی **هر** GET زیر
`auth+store.customer` (نه فقط خرید) یک `CustomerAccount` تازه می‌ساخت.

## تغییر

فقط سه فایل، هرکدام یک خط منطقی:

1. **`EnsureCustomerAccountResolved`**: `resolveCustomerAccount()` (ساخت)
   → `findCustomerAccount()` (فقط خواندن، nullable). از این پچ، این
   میان‌افزار دیگر هرگز چیزی نمی‌سازد.
2. **`CheckoutController::store`**: به‌جای خواندنِ Attribute (که حالا
   ممکن است null باشد)، مستقیماً `resolveCustomerAccount()` را همین‌جا
   صدا می‌زند — این دقیقاً لحظه‌ی «خرید انجام شد» است.
3. **`AccountsController::renew`**: **هیچ تغییری لازم نبود.** تمدید
   همیشه روی یک Account از‌قبل‌موجود است، و آن Account نمی‌توانست بدون
   یک CustomerAccount از قبل ساخته شود (چون خودِ خرید اولیه از همین
   پچ به بعد آن را ساخته) — پس در لحظه‌ی Renewal، CustomerAccount
   همیشه از قبل وجود دارد؛ `findCustomerAccount` آن را پیدا می‌کند،
   نه صفر.

## چرا فقط همین سه فایل — بقیه چرا لازم نبود؟

`AccountsController::index/show`، `OrdersController::index`،
`ReferralController::show`، `OrderController::show` — همه از قبل با
همان الگوی امنِ `$customer?->id` یا شرط صریح `! $customer` نوشته شده
بودند (نه چون کسی این تصمیم را پیش‌بینی کرده بود، بلکه چون همیشه باید
حالتِ «کاربر لاگین است ولی چیزی برای دیدن ندارد» را هم پوشش می‌دادند).
با `findCustomerAccount` که حالا واقعاً می‌تواند `null` برگرداند، همان
کدِ قبلی خودش‌به‌خود درست کار می‌کند: لیست خالی یا ۴۰۴، نه خطا.

هیچ تستِ موجودی هم نشکست: هر تستی که به یک `CustomerAccount` نیاز
داشت، از قبل آن را صریحاً با `resolveCustomerAccount()` در Setup خودش
می‌ساخت (نه با اتکا به عوارضِ جانبیِ Middleware) — این را قبل از تغییر
کد، با `grep` روی کل `tests/Feature/Website/` تایید کردم.

## اثر روی Wallet (بخشی که تغییر نکرد و نباید می‌کرد)

`WalletService::balanceIn()`/`walletForContext()` از قبل کاملاً
مستقل از `CustomerAccount` بودند (بند خودِ کد: «بدون نیاز به ساخت
CustomerAccount» — کلید Wallet ترکیب `user_id`+`scope_key` است). یعنی
نمایش موجودی کیف‌پول (صفحه‌ی Wallet، صفحه‌ی Checkout) هیچ‌وقت به این
مسئله ربطی نداشت و دست‌نخورده ماند.

## تست

`tests/Feature/Website/LazyCustomerAccountCreationTest.php`:
- بازدید از Orders/Wallet/Accounts/Referral (Main) → صفر CustomerAccount.
- بازدید از یک فروشگاه نماینده که کاربر هیچ سابقه‌ای با آن ندارد → صفر
  CustomerAccount (دقیقاً سناریوی سؤال نفر ۳).
- `GET checkout.show` → صفر؛ `POST checkout.store` → دقیقاً یکی.

## به‌روزرسانی مستندات

`docs/history/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md` یک بخش
«به‌روزرسانی» گرفت که به این پچ ارجاع می‌دهد — نتیجه‌گیری قبلی نفر ۳
حذف نشد (برای شفافیتِ تاریخچه‌ی تصمیم)، فقط علامت‌گذاری شد که override
شده.

## محدودیت این پچ

بدون PHP اجرا‌پذیر نوشته شده:

    php artisan test --filter=LazyCustomerAccountCreationTest
    php artisan test --filter=CheckoutFlowTest
    php artisan test --filter=AccountPanelTest
    php artisan test --filter=ResellerCommerceFlowTest
    php artisan test --filter=ResellerManagementTest

(چهارتای آخر برای اطمینان از این‌که تغییر میان‌افزار مشترک چیزی را
نشکسته — همه‌شان از قبل CustomerAccount خودشان را صریح می‌سازند، پس
باید بدون تغییر رفتار سبز بمانند.)
