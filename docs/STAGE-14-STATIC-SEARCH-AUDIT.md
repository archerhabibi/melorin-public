# مرحله‌ی ۱۴ سند (بند ۵۹) — Static Search

مرجع: سند معماری v2.0، بند ۵۷ («Static Search نهایی») و بند ۵۹، مرحله
۱۴: «Legacy Nameها کاملاً حذف شوند.»

## نتیجه‌ی Audit: این مرحله از قبل کامل است

جست‌وجوی کامل امروز، دقیقاً طبق دستور بند ۵۷:

```
grep -rn -E "base_price|core_price|sold_price|custom_price|corePrice|soldPrice|sellingPriceForReseller" \
    app resources routes config tests database/factories database/seeders
```

**صفر نتیجه** (به‌جز خودِ فایل تستِ نگهبان که این عبارت‌ها را به‌عنوان
Pattern جست‌وجو نگه می‌دارد، نه استفاده‌ی واقعی).

`database/migrations/` عمداً بیرون از دامنه است (بند ۵۴/۵۷ — تاریخچه‌ی
DB نباید بازنویسی شود). تنها مواردی که آنجا این نام‌ها را دارند، دو
دسته‌اند و هر دو درست‌اند: مایگریشن‌های واقعاً تاریخیِ پیش از فاز ۵
(مثلاً `create_reseller_product_prices_table`)، و خودِ مایگریشنِ
rename در فاز ۵ (`rename_legacy_pricing_columns`) که وظیفه‌اش دقیقاً
اشاره به نام قدیمی برای تبدیلش است.

## چرا این مرحله قبلاً بسته شده بود

این جست‌وجو یک‌بار مصرف نیست — از فاز ۱۲ (`PricingNamingAndReportsTest`)
به‌صورت یک تست دائمی در سوییت وجود دارد:

```php
#[Test]
public function no_legacy_pricing_name_remains_in_code_views_or_tests(): void
```

این تست حتی از دامنه‌ی بند ۵۷ فراتر رفته و علاوه بر ۷ نام ممنوعه‌ی
اصلی، سه نام مرتبط دیگر (`sellingPrice`, `selling_price`,
`setSellingPrice`) را هم پوشش می‌دهد، و روی هر فایل `.php`/`.blade.php`
زیر `app, resources, routes, config, tests, database/factories,
database/seeders` اجرا می‌شود — یعنی همان لیست دقیق بند ۵۸.

## نتیجه

مرحله‌ی ۱۴ سند نیاز به هیچ تغییر کدی جدید نداشت؛ محافظِ دائمی‌اش از
فاز ۱۲ سرِ جایش است و امروز هم صفر Regression نشان داد. این سند فقط
Audit امروز را برای رکورد ثبت می‌کند — دقیقاً مثل چیزی که در Audit فاز
۵ برای مراحل ۱، ۲، ۳، ۴ و ۶ اتفاق افتاد.

`php artisan test tests/Feature/Architecture/PricingNamingAndReportsTest.php`
انتظار: سبز.
