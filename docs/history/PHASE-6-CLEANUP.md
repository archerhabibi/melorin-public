# فاز ۶ — Cleanup (نسخه 3.3.3)

**قاعده:** فقط پاک‌سازی؛ هیچ رفتار، Route، Migration یا Schema تغییر نکرد. `php artisan test`: ۴۸۳ تست سبز **قبل** و **بعد** از فاز (اجرای واقعی، PHP 8.3).

## انجام شد
| مورد | تغییر |
|---|---|
| ساختار docs | `canonical/` (منبع تصمیم) · `operations/` (Deploy/Staging) · `history/` (PHASE-*، تصمیم‌ها، DEPRECATED). `docs/README.md` و `history/README.md` اضافه شد. ۴۱ سند با `git mv` جابه‌جا شد (پوشه‌ی history اکنون ۴۴ فایل)؛ همه‌ی ارجاع‌ها (کد، تست، README، خود اسناد) بازنویسی و بررسی شد: صفر ارجاع شکسته |
| اسناد DEPRECATED | `ARCHITECTURE.md` ریشه ← `history/ARCHITECTURE-3.1.1-SUPERSEDED.md`؛ Master v2.3 و Website v1.2 با بنر `Status: DEPRECATED` در `history/` |
| `VERSION` | از ۵۱ کیلوبایت تاریخچه به یک شماره (`3.3.3`) تبدیل شد؛ تاریخچه ← `history/CHANGELOG.md`. (هیچ کدی `VERSION` را نمی‌خواند.) |
| کامنت‌های تاریخچه‌ای | ده‌ها کامنت (در مجموع ۱۳۷ فایل در این فاز تغییر کرد: ۹۵ ویرایش، ۴۱ جابه‌جایی، ۱ حذف) با «نفر ۳ / پچ ۳.۲.۱۷ / Roadmap بند N / فاز W4» در `app/ routes/ resources/ database/ tests/` به توضیح وضعیت فعلی بازنویسی شد (دلیل/قاعده حفظ، سابقه حذف شد). ارجاع به `docs/history/...` فقط جایی ماند که لازم است |
| کد مرده | `resources/views/welcome.blade.php` (هیچ route نداشت)؛ ۶ `use` بلااستفاده (۳ در app، ۳ در tests) |
| تست‌های تکراری | `makeProduct` تکراریِ `CheckoutFlowTest` و `ResellerCommerceFlowTest` به Trait مشترک `InteractsWithWebsiteFixtures::makeSellableProduct(price, overrides)` رفت |
| Artifact | `.env`، `webhook.json`، `*.sql`، `.phpunit.result.cache`، `vendor/`، `node_modules/` در خروجی فاز ۶ نیستند |

## چرا Migration ادغام نشد
پروژه Release نشده، پس از نظر تئوری می‌شد ۸۲ Migration را به یک Baseline تبدیل کرد (و Backfillهای بی‌مصرف را حذف). عمداً انجام نشد، چون دیتابیس شما همین الان تا batch 25 اجرا شده و ادغام یعنی `migrate:fresh` و از دست رفتن داده‌ی فعلی، بدون اینکه فاز ۶ به آن نیاز داشته باشد. اگر می‌خواهید: یک فاز جدا («Baseline Squash») با `schema:dump` + حذف Backfillها، قبل از اولین Tag.

## عمداً دست نخورد (کار بعدی یا تصمیم شما)
- `AccountService::legacyTestAccountPurchase` و Bridgeهای «backward-compatible» ربات: هنوز مسیر زنده‌اند و تست دارند.
- ستون `users.reseller_id` (بلااستفاده؛ MembershipGuardTest فقط خواندن/نوشتن آن را در کد ممنوع می‌کند) ← حذف با Migration، ترجیحاً در Baseline Squash.
- متدهای رابطه‌ی بی‌مصرف (`Payment::reviewer`، `Reseller::productPrices` …) و `Filament\Reseller\Pages\ContentSettings`/`Widgets\StatsOverview`: Filament صفحه/ویجت را خودکار Discover می‌کند، پس «بی‌مصرف» بودن قطعی نیست.
- هلپرهای تکراری بقیه‌ی تست‌ها (`makeProduct` در ۸ فایل Core/Provisioning/Concurrency با پارامترهای متفاوت، `buyer`، `mainWalletOwner`): ادغام‌شان ریسک بی‌دلیل روی تست‌های سبز است.
- ۳۷۲ ارجاع «بند N» و ۶۲ «Rule N» داخل کد: به Contract ارجاع می‌دهند و بخشی‌شان شماره‌ی بندِ سند قدیمی است؛ بازنویسی‌شان نیاز به تطبیق یکی‌یکی با Contract 2.8 دارد (فاز ۳ ماتریس).
