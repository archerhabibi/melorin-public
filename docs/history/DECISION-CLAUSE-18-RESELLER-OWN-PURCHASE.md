# تصمیم بند ۱۸ ↔ Rule 2/13 — خرید شخصی صاحب نماینده از Main

مرجع: بند ۱۸ سند معماری v2.0، بخش «یافته‌های نیازمند تصمیم» در
`docs/history/PHASE-14-FINAL-MODEL-TESTS.md` (مورد ۲)، تست
`FinalModelSpecTest::open_decision_a_reseller_buying_directly_from_main_pays_which_price`
(قبلاً Skipped).

## تصمیم نهایی

وقتی **خودِ صاحبِ یک نماینده** شخصاً از فروشگاه اصلی خرید می‌کند:

- **Context** همچنان `main` می‌ماند (Rule 13 — نماینده مشتری مستقیم
  Main باقی می‌ماند؛ `order->reseller_id = null`).
- **مبلغ کسرشده** `reseller_price` است، نه `main_price` (بند ۱۸).

یک مشتری عادی (بدون نمایندگی) بدون تغییر همچنان `main_price` می‌پردازد؛
این تصمیم فقط شامل صاحبِ خودِ نماینده است.

## پیاده‌سازی

دقیقاً همان‌جایی که خودِ سند در بند ۱۸ پیشنهاد داده بود: فقط
`PriceSnapshot::forMainStore()` + یک شرط، بدون تغییر مدل Context یا
جدول `orders`:

- `PriceSnapshot::forMainStore(Product $product, bool $buyerOwnsAReseller = false)`
  — وقتی `$buyerOwnsAReseller` باشد، از `product->resellerPrice()`
  به‌جای `product->mainPrice()` استفاده می‌کند؛ مقدار همچنان زیر ستون
  `main_price` سفارش می‌نشیند (چون Context عوض نشده).
- `PriceSnapshot::for()` این پرچم را می‌پذیرد و فقط در مسیر Main پاس
  می‌دهد.
- `PurchaseService::execute()` و `RenewalService::execute()` هر دو:
  `$buyerOwnsAReseller = $store->isMain() && $customer->user->resellerAccount()->exists();`

تمدید هم همین منطق را دارد: تمدید از نظر مالی یک خرید کامل است (همان
اصلی که خودِ RenewalService از قبل رعایت می‌کرد)، پس صاحبِ نماینده‌ای
که شخصاً در Main اکانتی گرفته، هنگام تمدید هم `reseller_price` می‌دهد.

## تست

`FinalModelSpecTest::open_decision_...` (Skipped) با
`a_reseller_owner_buying_directly_from_main_pays_reseller_price_not_main_price`
جایگزین شد. `a_reseller_owner_stays_a_direct_main_customer` (Rule 13)
نیاز به تغییر نداشت — چون خودش را نسبت به مقدار واقعی `order->main_price`
می‌سنجد، نه یک عدد ثابت.
