# Melorin — Changelog (تاریخچه‌ی نسخه‌ها)

> این فایل تاریخچه است، منبع تصمیم نیست. مرجع معماری: `docs/canonical/`.
> قبلاً این متن داخل فایل `VERSION` بود؛ در فاز ۶ جدا شد تا `VERSION` فقط یک شماره باشد.

---

3.3.20 - B4.3 Guest Checkout UX (Contract مستقل `CUSTOMER-GUEST-CHECKOUT-UX-CONTRACT.md`؛ **بدون Migration/Route/تغییر Core**؛ قواعد داده‌ی Guest G2/G3/G5/G7/G9 دست‌نخورده): فرم مهمان با کامپوننت‌های دیزاین‌سیستم بازطراحی شد — مراحل (`x-ui.steps`: اطلاعات ← ورود/ثبت‌نام ← پرداخت)، Breadcrumb، کارت «خلاصه‌ی خرید» (مدت/حجم/قیمت نهایی/موجودی محدود)، `x-ui.field` با `aria-invalid`/`aria-describedby`، `autocomplete`/`inputmode`/`dir=ltr` برای موبایل، پیام اطمینان («تا قبل از پرداخت حسابی ساخته نمی‌شود»)، میان‌بر ورود. **ویرایش اطلاعات:** Pending لینک «ویرایش اطلاعات» دارد؛ فرم از نشست فعالِ همان مرورگر/Context پر می‌شود و Submit نشست قبلی را باطل می‌کند. **ظرفیت تکمیل:** فرم/ثبت برای تعرفه‌ی ظرفیت‌تکمیل به صفحه‌ی همان تعرفه با هشدار برمی‌گردد و نشستی ساخته نمی‌شود؛ اگر ظرفیت بعد از شروع نشست تمام شود Pending به‌جای ورود/ثبت‌نام پیام و لینک جایگزین‌ها می‌دهد (قبلاً مهمان پس از ورود به بن‌بست Checkout می‌رسید). صفحه‌ی تعرفه دو CTA هم‌وزن + توضیح؛ `entry-link` با دکمه‌ی دیزاین‌سیستم؛ Layout پیام `warning` را نشان می‌دهد. تست: `GuestCheckoutUxTest` (۱۱ تست)؛ با ۲ جهش عمدی (حذف محافظ ظرفیت، نشت Prefill) راستی‌آزمایی شد. **اجرا نشده:** MySQL واقعی، مرورگر/Build فرانت (کلاس‌های Tailwind جدید فقط با Build دیده می‌شوند)، Telegram. **Out of scope:** نرمال‌سازی `guest_phone` (D-17)، صفحه‌ی خطای اختصاصی نشست منقضی، Google پیش از فرم.

3.3.19 - B4.1 Product Catalog (Contract مستقل `CUSTOMER-CATALOG-CONTRACT.md`؛ **بدون Migration**): کاتالوگ از «Query پراکنده در Facade سایت + Queryهای جدا در دو ربات» به یک سرویس واحد Core تبدیل شد — `Catalog\ProductCatalogService` (`catalog`/`find`/`alternatives`) + `CatalogQuery` + DTOهای `CatalogItem`/`CatalogCategory`/`Catalog` + `CatalogText`؛ `WebsiteCatalogFacade` فقط Adapter است. **سایت:** صفحه‌ی خانه جست‌وجو (فارسی‌محور: ارقام فارسی/عربی، «ي/ك» عربی، نیم‌فاصله، چندواژه‌ای)، فیلتر سبد (با تعداد)، «فقط موجودها» و مرتب‌سازی (ارزان‌ترین/گران‌ترین/بیشترین مدت/بیشترین حجم) دارد؛ حالت خالی، «نتیجه‌ای نیست»، پاک‌کردن فیلترها و `noindex` برای صفحه‌ی فیلترشده؛ ورودی مخرب (`q[]=`، عدد غول‌آسا، RLO) بی‌خطا به پیش‌فرض برمی‌گردد. **ظرفیت:** تعرفه‌ی «ظرفیت تکمیل» دیده می‌شود (نشان) ولی دکمه‌ی خرید ندارد و جایگزین هم‌سبد پیشنهاد می‌شود؛ «N عدد باقی مانده» برای ۱..۵؛ حجم نامحدود صریح («نامحدود»). مرجع واقعی ظرفیت همچنان `PurchaseGuard`/رزرو اتمیک است. **ربات‌ها (اصلی و نماینده):** سبد/تعرفه از همان کاتالوگ (سبد خالی یا بسته نمی‌آید)، برچسب دکمه مشترک با نشان ظرفیت، کلیک روی ظرفیت‌تکمیل یا دکمه‌ی قدیمیِ تعرفه‌ی مخفی‌شده پیام می‌دهد و سفارشی نمی‌سازد (قبلاً `findOrFail` و خطا)؛ قیمت ربات نماینده = `customers_price` همان سایت. **باگ بسته‌شده:** در فروشگاه اصلی صفحه‌ی تعرفه‌ی «سبد بسته» باز می‌شد ولی خرید رد می‌شد؛ اکنون 404 است (تعرفه، Checkout، Guest). **کارایی:** فروشگاه نماینده قبلاً به‌ازای هر تعرفه یک Query قیمت می‌زد؛ اکنون تعداد Query ثابت است (تست N+1). تست Parity: فهرست و `find` با `ResellerPricingService::isSellable` در ۷ حالت (تعرفه/سبد غیرفعال، سبد بسته برای نمایندگان، سبد بسته‌شده توسط نماینده، قیمت غیرفعال، بدون قیمت، ...) یکی است. تست: `ProductCatalogTest` (۲۶ تست)؛ کل مجموعه SQLite: **۸۵۴ سبز + ۵ skip (Redis)** (قبل: ۸۲۸ + ۵) با ۱۲ جهش عمدی (سبد بسته در find/list، قیمت reseller_price، ظرفیت‌تکمیل آخر نیست، فیلتر موجود، نرمال‌سازی جست‌وجو، noindex، نگهبان دو ربات، N+1، دکمه‌ی خرید در ظرفیت تکمیل، نمایندگی غیرفعال) راستی‌آزمایی شد. **اجرا نشده:** MySQL واقعی، مرورگر/Build فرانت (کلاس‌های Tailwind جدید فقط با Build دیده می‌شوند)، Telegram واقعی. **مشاهده (خارج از دامنه، اصلاح نشد):** `ResellerBotSetting::forReseller()` با `firstOrCreate` مقدار پیش‌فرض DB (`bot_enabled=1`) را روی مدل تازه‌ساخته برنمی‌گرداند؛ اولین پیامِ یک نماینده‌ی نو ممکن است «فروشگاه موقتاً غیرفعال است» بگیرد. **Out of scope:** تصویر/توضیح/مقایسه (B4.2)، نام/قیمت اختصاصی نماینده (B5.3)، ظرفیت سرور در کاتالوگ (D-19)، صفحه‌بندی (D-20)، ترتیب دستی (D-21).

3.3.18 - B3.5 Profile Center (Contract مستقل `CUSTOMER-PROFILE-CONTRACT.md`؛ **Migration جدید** `2026_10_06_000001`: `users.full_name_edited_at` nullable): صفحه‌ی `/profile` از «نمایش نام/ایمیل» به مرکز پروفایل ارتقا یافت — درصد و چک‌لیست تکمیل (نام، موبایل، تأیید ایمیل؛ برای کاربر بدون ایمیل «تأیید ایمیل» شمرده نمی‌شود)، فرم ویرایش **نام و موبایل** (`POST /profile`، `throttle:10,1`)، ایمیل فقط‌نمایشی (Change Email = D-12 همچنان خارج از دامنه)، «عضو Melorin از»/«عضویت در این فروشگاه از» (شمسی)، نشان اتصال‌ها؛ کارت‌های B2.4/B2.5 بدون تغییر. **باگ بسته‌شده:** وب‌هوک هر دو ربات روی هر پیام `full_name` را با نام تلگرام بازنویسی می‌کرد، یعنی نامِ ثبت‌نام/ویرایش‌شده در سایت با اولین پیام ربات از بین می‌رفت (به‌ویژه برای کاربر Website که تلگرام وصل کرده). اکنون قاعده‌ی واحد در Core است (`ProfileCenterService::applyTelegramName`): نام ویرایش‌شده، یا نام کاربر Website، بازنویسی نمی‌شود؛ کاربر ربات که نامش را ویرایش نکرده مثل قبل دنبال تلگرام می‌ماند. **ربات:** «👤 حساب کاربری» از همان `overview` Core می‌خواند (نام، موبایل، ایمیل+وضعیت، عضویت شمسی/میلادی، موجودی، «برای تکمیل پروفایل») با دکمه‌های ویرایش نام/موبایل که به همان `ProfileCenterService::update` می‌روند؛ زدن دکمه‌ی منو از جریان ویرایش خارج می‌کند (`ProfileHandler`، State `PROFILE_AWAITING_NAME/PHONE`). **قواعد Core:** نام ۲..۱۰۰ نویسه با پاک‌سازی نویسه‌ی کنترلی/صفر-عرض/جهت‌دهی دوطرفه (نیم‌فاصله‌ی فارسی می‌ماند) و ردِ `<>`/لینک؛ موبایل نرمال به `09xxxxxxxxx` (ارقام فارسی، `+98`، `0098`، جداکننده) یا E.164؛ یکتایی با فرم‌های قدیمیِ ذخیره‌شده و کاربر حذف‌نرم و پیام عمومی (بدون افشای مالک)؛ فهرست‌سفید فیلدها (ایمیل/وضعیت/telegram_id/... هرگز از این مسیر)؛ قفل ردیف؛ Audit `profile.updated` فقط با نام فیلدها (PII ثبت نمی‌شود)؛ GET هیچ‌چیز نمی‌نویسد. منطق در Core: `Customer\ProfileCenterService` + `ProfileOverview` + `ProfileCenterException` + `Support\PhoneNumber`؛ `WebsiteProfileFacade` و `ProfileHandler` فقط Adapter. تست: `ProfileCenterTest` (۳۳ تست)؛ کل مجموعه SQLite: **۸۲۸ سبز + ۵ skip (Redis)** (قبل: ۷۹۵ + ۵). با ۱۰ جهش عمدی (حذف نگهبان نام ویرایش‌شده، نگهبان نام Website، یکتایی موبایل، مهر `full_name_edited_at`، نشت مقدار در Audit، دروازه‌ی وضعیت، خروج با دکمه‌ی منو، ردِ HTML/لینک، تشخیص «بدون تغییر»، throttle) راستی‌آزمایی شد. **اجرا نشده:** MySQL واقعی (Migration و `REPLACE(...)` در بررسی یکتایی)، مرورگر/Build فرانت، Telegram واقعی. **Out of scope:** Change Email (D-12)، Email برای کاربر ربات (D-14)، OTP موبایل (D-18)، نرمال‌سازی `phone` در تطبیق تصادم Guest (D-17)، آواتار، ترجیحات اعلان، حذف حساب.

3.3.17 - B3.4 Ticket Center (Contract مستقل `CUSTOMER-TICKETS-CONTRACT.md`؛ **Migration جدید** `2026_10_05_000001`: `tickets.reseller_id` nullable + FK + index): مشتری از سایت (Main و فروشگاه نماینده) تیکت پشتیبانی ثبت می‌کند، گفتگو را می‌بیند، پاسخ می‌دهد و تیکت را می‌بندد. فهرست با فیلتر وضعیت («پاسخ داده شد» بالاتر)، صفحه‌ی گفتگو (برچسب «پشتیبانی»/«شما»، نام واقعی ادمین هرگز نشان داده نمی‌شود، متن همیشه escape)، اعلان داشبورد `ticket.answered` (لینک مستقیم به تیکت)، آیتم «پشتیبانی» در منو. **مالکیت `user_id + reseller_id`**: تیکت دیگران/Context دیگر ⇒ 404. سقف ۵ تیکت باز به‌ازای هر کاربر در هر فروشگاه (با قفل ردیف)، ضد دوبار-کلیک (۳۰ ثانیه)، Throttle، پاک‌سازی کاراکتر کنترلی. اطلاع به ادمین از همان رویدادهای ربات و **best-effort** (خرابی Listener فقط Log می‌شود، مشتری 500 نمی‌بیند). **اصلاح‌های جانبی:** (۱) ربات فقط تیکت‌های Main را می‌بیند (`reseller_id IS NULL`) تا تیکت فروشگاه نماینده ربات را به «ادامه‌ی همان تیکت» نبرد؛ (۲) اطلاع ادمین برای کاربر بدون تلگرام ایمیل را نشان می‌دهد (قبلاً «شناسه: » خالی) و برای تیکت نماینده فروشگاه را؛ (۳) ستون «فروشگاه» در جدول تیکت‌های پنل ادمین. منطق در Core: `Customer\TicketCenterService` + `TicketCounts` + `TicketCenterException`؛ `WebsiteTicketFacade` فقط Adapter. تست: `TicketCenterTest` (۲۵ تست)؛ کل مجموعه SQLite: **سبز** (۸۰۰ تست: ۷۹۵ سبز + ۵ skip Redis). با ۸ جهش عمدی (حذف scope فروشگاه، scope کاربر، قفل تیکت بسته، سقف، ضد-تکرار، نشت نام ادمین، فیلتر ربات، try/catch اطلاع‌رسانی) راستی‌آزمایی شد. **اجرا نشده:** MySQL واقعی (Migration/FK)، مرورگر. **Out of scope:** پیوست فایل، ایمیل/Push به مشتری هنگام پاسخ، پنل تیکت نماینده (B5)، باز کردن مجدد تیکت بسته، نشان خوانده‌نشده‌ی ماندگار.

3.3.16 - B3.3 Wallet Center (Contract مستقل `CUSTOMER-WALLET-CONTRACT.md`، بدون تغییر Schema): صفحه‌ی کیف‌پول از «موجودی + جدول ساده» به مرکز کیف‌پول ارتقا یافت — کارت‌های موجودی / دریافتی و پرداختی ۳۰ روز اخیر / شارژ در انتظار؛ «شارژهای اخیر» با وضعیتِ «منتظر چه کاری» (ثبت رسید، در حال بررسی، نتیجه‌ی درگاه، ناتمام، تأیید، ناموفق، بازگشت) و لینک «ثبت رسید» فقط وقتی رسید لازم است؛ گردش حساب با فیلتر جهت/نوع/بازه‌ی تاریخ، توضیح هر تراکنش و لینک به سفارش (فقط سفارشِ خودِ کاربر در همین Context). صفحه‌ی شارژ: موجودی فعلی، حداقل مبلغ، دکمه‌های مبلغ پیشنهادی (لینک GET بدون JS؛ سازگار با CSP؛ `return_product` حفظ می‌شود)، مهاجرت به کامپوننت‌های Design System. **باگ‌های B3.1/قدیمی که بسته شد:** (۱) باز کردن `GET /wallet` یک Wallet خالی می‌ساخت (نقض D4.1)؛ اکنون هیچ‌چیز نمی‌نویسد. (۲) هر شارژ درگاهیِ رهاشده تا ابد «شارژ در انتظار تأیید» اعلام می‌شد؛ اکنون پس از ۲۴ ساعت «ناتمام» است و شمرده نمی‌شود، و داشبورد و کیف‌پول از یک شمارنده می‌خوانند. (۳) توضیح `referral_bonus` (حاوی نام کامل کاربر دعوت‌شده) هرگز نمایش داده نمی‌شود. **دو تقویم:** همه‌ی تاریخ‌های کیف‌پول هم شمسی و هم میلادی نمایش داده می‌شود (`App\Support\JalaliDate`، حسابی، بدون وابستگی)؛ ذخیره/فیلتر میلادی ماند. منطق در Core: `Customer\WalletCenterService` + `WalletCenterFilter` + `WalletChargeEntry` + `WalletOverview` (قابل‌استفاده برای ربات)؛ `WebsiteWalletFacade` فقط نگاشت URL. فیلتر نامعتبر بی‌صدا حذف می‌شود (نه 422) و لینک‌های صفحه‌بندی فقط فیلتر پاک‌سازی‌شده را نگه می‌دارند. مسیرهای POST شارژ/رسید و نام فیلدها بدون تغییر. تست: `WalletCenterTest` (۲۷ تست)؛ کل مجموعه SQLite: **۷۷۱ سبز + ۵ skip (Redis)** (قبل: ۷۴۴ + ۵). تست‌ها با پنج «جهش» عمدی (حذف بررسی مالکیت سفارش، حذف قید ۲۴ ساعته، نشت توضیح پاداش، حذف scope Context، حذف قید reseller_id شارژها) راستی‌آزمایی شدند. **اجرا نشده:** MySQL واقعی (`SUM(CASE…)` و فیلتر تاریخ؛ فقط SQLite)؛ نمایش بصری در مرورگر (فقط تست HTML؛ Build فرانت اجرا نشد).

3.3.15 - B3.2 Service Management (Contract مستقل `CUSTOMER-SERVICES-CONTRACT.md`؛ Migration: `accounts.usage_synced_at`): مشاهده‌ی مصرف و تمدید سرویس، بدون «ارتقا» (تصمیم صاحب پروژه). **باگ‌های B3.1 که بسته شد:** (۱) `traffic_used_gb` هیچ‌وقت از پنل خوانده نمی‌شد ⇒ نوار مصرف و هشدار ۹۰٪ داشبورد همیشه صفر بود؛ اکنون `Customer\AccountUsageService` + `SupportsUsageReport` در درایورهای Marzban/PasarGuard (`used_traffic`) و Sanaei (`up+down`) + Job `accounts:sync-usage` هر ۱۵ دقیقه (سقف ۲۰۰ در هر اجرا) + دکمه‌ی دستی (Throttle: route ۱۲/دقیقه و ۱۲۰ ثانیه برای هر سرویس)؛ شکست پنل عدد قبلی را خراب نمی‌کند. (۲) پیش‌بررسی تمدید در سایت نماینده با `mainPrice()` سنجیده می‌شد نه `customers_price`؛ اکنون `RenewalService::priceFor()` تنها منبع قیمت (quote و execute). (۳) تمدید وضعیت اکانت را نمی‌سنجید و پرداخت می‌توانست اکانتِ مسدودشده توسط نماینده (`disabled`) را «active» کند؛ اکنون فقط `active`/`expired` تمدید می‌شود (در quote و execute، قبل از هر Debit). (۴) `POST renew` Throttle نداشت ⇒ `throttle:6,1`. **تازه:** `RenewalService::quote()` (پیش‌فاکتور فقط‌خواندنی؛ Wallet/Order نمی‌سازد)؛ `Customer\AccountManagementService` + `ServiceOverview` (Core؛ قابل‌استفاده برای ربات)؛ `Account::displayState()`/`usedTrafficGb()`/`trafficTone()`/`isRenewableStatus()`؛ ارسال دوباره‌ی همان فرم پس از تمدید موفق، موفقیت نشان می‌دهد (به‌جای خطای کمبود). Website: `WebsiteServiceFacade`؛ route جدید `accounts.usage.refresh`؛ بازطراحی فهرست و جزئیات (نوار مصرف، برچسب وضعیت، کارت تمدید با مبلغ/موجودی/کمبود و لینک شارژ، زمان آخرین هم‌گام‌سازی)؛ داشبورد رنگ مصرف را از `Account::trafficTone()` می‌گیرد. تست: `ServiceManagementTest` (۳۹ تست)؛ کل مجموعه SQLite: **۷۴۴ سبز + ۵ skip (Redis)** (قبل: ۷۰۵ + ۵). تست‌ها با «جهش» عمدی (حذف دروازه‌ی وضعیت، قیمت اشتباه، نگهبان null مصرف) راستی‌آزمایی شدند. **اجرا نشده:** پنل واقعی (فقط `Http::fake`؛ فرمت دقیق `used_traffic`/`up+down` باید روی Staging با پنل واقعی تأیید شود).

3.3.14 - B3.1 Customer Dashboard (Contract مستقل `CUSTOMER-DASHBOARD-CONTRACT.md`، بدون تغییر Schema): صفحه‌ی `GET /dashboard` (`website[.store].dashboard`) به‌عنوان نقطه‌ی ورود پنل مشتری — شمارنده‌ی سرویس فعال/رو‌به‌انقضا/منقضی، ۴ سرویسِ نزدیک‌ترین انقضا با نوار مصرف حجم و روز باقی‌مانده، کارت کیف‌پول (موجودی + ۵ تراکنش آخر + شارژ)، و اعلان‌های مشتق‌شده از وضعیت زنده (انقضا، حجم، سفارش ناموفق، شارژ در انتظار تأیید، کیف‌پول خالی؛ بدون ذخیره). منطق در Core: `Customer\CustomerDashboardService` (فقط‌خواندنی؛ Wallet خالی هم روی GET ساخته نمی‌شود) + `WebsiteDashboardFacade` (فقط نگاشت هدف→URL). `Account`: `scopeActiveNow`، `scopeLapsed`، `remainingDays()`، `trafficUsagePercent()`. UI: کامپوننت `x-ui.progress`؛ «داشبورد» اولین آیتم منو و ریشه‌ی Breadcrumb؛ لینک نام کاربر در Header از Wallet به داشبورد. تست: `CustomerDashboardTest` (۲۰ تست) + یک تست Navigation؛ **نوشته‌شده، اجرا نشده**.

3.3.13 - B2.5 Session Security (Contract مستقل `SESSION-SECURITY-CONTRACT.md`، بدون تغییر Schema؛ بسته‌شدن D-16): (S3) کاربر پس از ورود مسدود/غیرفعال شود نشست زنده‌اش با اولین درخواست بسته می‌شود (Middleware `website.session`)؛ (S4) سقف مطلق عمر نشست `SESSION_ABSOLUTE_LIFETIME` (پیش‌فرض ۷ روز، ۰ = خاموش؛ نشست «به‌خاطر بسپار» مستثنا) با مُهر شروع نشست از Listener روی `Login`؛ (S2) `AuthenticateSession` روی مسیرهای `auth` ⇒ ابطال دستگاه‌های دیگر مستقل از Driver؛ (S5/S7) Profile: «دستگاه‌ها و نشست‌های فعال» (برچسب دستگاه، IP ماسک‌شده، بستن یکی یا همه‌ی دیگر با تأیید رمز؛ فقط `SESSION_DRIVER=database`؛ شناسه‌ی مبهم HMAC به‌جای Session ID)؛ (S8) مالکیت نشست از payload اثبات می‌شود چون `sessions.user_id` برای نشست Admin/Reseller هم پر می‌شود و با id کاربر عادی برخورد دارد؛ `EmailAuthService::revokeSessions` دیگر نشست Admin را نمی‌بندد؛ (S9) تغییر رمز موجود از Profile (`identity.password.update`) و بستن نشست‌های دیگر (اولین رمز هم)؛ (S10) Cookie «به‌خاطر بسپار» از ۴۰۰ روز به ۳۰ روز (`AUTH_REMEMBER_MINUTES`)؛ (S11) Preflight: `session_driver`، `session_http_only`، `session_same_site`، `session_lifetime`، `session_domain`. Audit تازه: `identity.sessions_revoked`، `identity.session_terminated`، `identity.password_changed`، `identity.password_change_rejected`. رفع تست B2.3 (`withCookie` روی نمونه‌ی تست ماندگار است). تست: `SessionSecurityTest` (۳۵ تست) + ۲ تست Preflight؛ کل مجموعه ۶۸۴ سبز.

---

3.3.12 - B2.4 Account Linking (Contract مستقل `ACCOUNT-LINKING-CONTRACT.md`، بدون تغییر Schema؛ بسته‌شدن D-13): (L1) منطق در Core: `Identity\AccountLinkingService`؛ (L7) اتصال Google کاربر واردشده از Profile با همان callback ثابت B2.1 (`mode=link` + `user_id` سمت سرور؛ Middleware `guest` از callback برداشته و در کنترلر enforce شد)؛ (L5/L6) Unlink Google/Telegram با تأیید رمز فعلی، POST + `throttle:5,1` و ضد Lock-out (Google بدون رمز قابل جدا شدن نیست؛ Telegram کاربر بدون Email/روش ورود نه)؛ (L9) تعیین اولین رمز از Profile برای کاربر Google؛ (L3) رفع باگ: callback تلگرام `telegram_id` موجود را بی‌صدا بازنویسی می‌کرد، اکنون رد + Audit؛ (L2) هویت متعلق به دیگری همچنان رد، بدون Merge؛ Profile بخش «روش‌های ورود» (وضعیت Email/رمز/Google/Telegram). Audit تازه: `identity.google_link_rejected`، `identity.google_unlinked`، `identity.google_unlink_rejected`، `identity.telegram_link_rejected`، `identity.telegram_unlinked`، `identity.telegram_unlink_rejected`، `identity.password_set`. تست: `AccountLinkingTest` (۳۵ تست؛ اجرا نشده).

---

3.3.11 - B2.3 Guest Checkout Identity Flow (بهبود جریان W3، بدون تغییر Schema و بدون تغییر Contract): (1) نشست Pending قبلیِ همان مرورگر/Context با شروع نشست تازه باطل می‌شود (`GuestCheckoutService::start(..., replacing)`)؛ (2) مسیر جدید `POST /guest-checkout/cancel` (`website[.store].guest-checkout.cancel`، `throttle:10,1`) و دکمه‌ی «لغو و شروع دوباره» ⇒ `discard()` شرطی pending→expired و پاک‌شدن Cookie؛ (3) کاربر واردشده Guest نمی‌شود: فرم/POST/Pending مستقیم به Checkout همان محصول می‌روند و رکورد ساخته نمی‌شود؛ (4) تصادم Email (G7) اکنون Case-insensitive است (`EmailIdentity::findUser`، سازگار با ردیف‌های قدیمیِ Mixed-case)؛ (5) Audit تازه: `identity.guest_checkout_consumed` (تنها پیوند Guest→User؛ بدون Email/Phone) و `identity.guest_checkout_discarded`؛ (6) Pending Page: مبلغ، دقایق باقی‌مانده، «ادامه با Google» و لغو؛ محصولِ بعداً غیرفعال‌شده نشست را باطل و 404 می‌دهد؛ (7) Login/Register هنگام نشست فعال یادآور «همین خرید ادامه پیدا می‌کند» نشان می‌دهند؛ (8) TTL و عمر Cookie یک منبع دارند (`GuestCheckoutService::TTL_MINUTES`). اصلاح جانبی: `MoneyIntegerGuardTest` محاسبات رنگ (`app/Support/Branding`) را که float طبیعی دارند و ربطی به پول ندارند نادیده می‌گیرد (شکست B1). تست: `GuestIdentityFlowTest` (۱۴ تست).

---

3.3.10 - B2.2 Email Authentication (Contract مستقل `EMAIL-AUTH-CONTRACT.md`، بدون تغییر Schema): (E1) Email در Register/Login/Forgot/Reset trim+lowercase و تطبیق Case-insensitive (`Identity\EmailIdentity`)، یکتایی Register شامل Soft-deleted، Rate Limit روی Email نرمال‌شده؛ (E2) Reset موفق: Email تأییدنشده ⇒ تأییدشده + remember_token جدید + ابطال Sessionهای User (ضد Pre-hijack؛ فقط `SESSION_DRIVER=database`) + Audit `identity.password_reset`؛ (E3) Audit تأیید با لینک (`identity.email_verified`)؛ منطق در `Identity\EmailAuthService`. کاربر Google بدون رمز از Reset اولین رمزش را می‌گیرد (تست). خارج از دامنه: Change Email (D-12)، Set Password از Profile (D-13/B2.4)، پیام Register (D-11). تست‌ها: `EmailAuthTest` ۱۵ تست سبز (SQLite).

---

3.3.9 - B2.1.1 (G21 گسترش): ورود موفق با **Email+Password** هم مثل Google، CustomerAccount فروشگاه مبدأ را Resolve/می‌سازد (`Identity\LoginMembershipService`، مشترک بین دو روش؛ Audit: `identity.password_customer_account_created`). Register همچنان نمی‌سازد. تست `ResellerGuestCheckoutTest` که Lazy بعد از ورود را قفل می‌کرد، مطابق تصمیم جدید اصلاح شد.

3.3.9 - B2.1 Google Sign-In (Opt-in): OIDC Authorization Code + PKCE + state + nonce بدون Socialite؛ جدول `user_identities` (کلید `(provider, sub)`)؛ `ExternalIdentityService` در Core (Link فقط با Email تأییدشده‌ی دو طرف، رد Email محلی تأییدنشده، رد کاربر غیرفعال/حذف‌شده)؛ callback ثابت روی Context اصلی با مقصد برگشت سمت سرور (نماینده/ادامه‌ی خرید Guest)؛ `LoginRequest`: کاربر بدون رمز دیگر 500 نمی‌دهد و کاربر غیرفعال/مسدود وارد نمی‌شود. Contract مستقل: `docs/canonical/GOOGLE-SIGNIN-CONTRACT.md` (D-6 بازگشایی و بسته شد؛ D-9/D-10 باز). **G21 (تصمیم صاحب پروژه، استثنای R7):** ورود/ثبت‌نام/Link موفق با Google، CustomerAccount همان User را در فروشگاه مبدأ (اصلی یا نماینده؛ سمت سرور در Session) Resolve/می‌سازد؛ Idempotent، بدون Wallet/Order، مسیر رد‌شده چیزی نمی‌سازد، شکست ساخت ورود را نمی‌شکند؛ Audit: `identity.google_customer_account_created`. اصلاح: متد کمکی `callback()` در `GoogleLoginTest` با متد `final` خود PHPUnit تداخل داشت و کل فایل لود نمی‌شد ⇒ `hitCallback()`. تست‌ها: `GoogleLoginTest` ۴۰ تست سبز (SQLite).

---

3.3.8 - تکمیل فاز ۹: `StuckOrderWatchdog` + `php artisan provisioning:recover-stuck` (هر ۵ دقیقه) برای بستن G-9-1 — سفارشِ گیرکرده در `provisioning` بیش از ۱۵ دقیقه: اگر اکانت ثبت شده باشد ← `account_created`، وگرنه ← `provision_failed` **بدون Retry خودکار و بدون هیچ حرکت مالی** (تصمیم Retry اجباری/Refund با ادمین)؛ بستن G-9-3 — `update-git.sh` از `storage/app` (رسیدها/لوگوها) هم Backup می‌گیرد. ۶ تست جدید. اجرای واقعی Staging و G-9-2 (Adopt اکانت یتیم) هنوز باز است.

---

3.3.7 - فاز ۹ (Staging Tooling): `php artisan melorin:preflight` (config/runtime/data، فقط‌خواندنی، Exit Code)، `GET /health/ready` (Readiness بدون Session و بدون نشت جزئیات)، Heartbeat Scheduler + پایش داده هر ۱۵ دقیقه (لاگ)، Gate Readiness در `update-git.sh`، اسکریپت‌های `scripts/staging/smoke.sh` و `backup-restore-drill.sh`، سندهای Runbook/Evidence/DR/Incident/Monitoring، رفع O-6 (PII در لاگ تلگرام). ۲۴ تست جدید. **اجرای واقعی Staging هنوز انجام نشده.**

جزئیات: docs/history/PHASE-9-STAGING.md

---

3.3.6 - فاز ۸ (Security Audit — Self-Audit): ۱۰ یافته بسته شد؛ مهم‌ترین‌ها: وب‌هوک تلگرام (اصلی و نماینده) Fail-closed، Callback زرین‌پال مقید به Authority (جلوگیری از Reject ناخواسته‌ی پرداخت دیگران)، Trusted Proxies، هدرهای امنیتی/HSTS، Throttle ثبت‌نام، Idempotency تمدید Website. ۱۶ تست جدید؛ ۴۹۳ سبز (+۵ Redis با flag). باز: Admin Authorization تخت (O-1) و ۷ مورد دیگر.

جزئیات: docs/history/PHASE-8-SECURITY-AUDIT.md
⚠️ قبل از Deploy: `TELEGRAM_WEBHOOK_SECRET` باید مقدار داشته باشد؛ نمایندگانِ بدون secret باید Reconnect webhook شوند.

---

3.3.5 - تکمیل فاز ۷: (۱) Baseline Squash: ۸۲ Migration → یک Baseline (`2026_10_03_000001_create_baseline_schema.php`)، بدون Backfill/Merge و بدون ستون‌های قدیمی (`users.reseller_id`)؛ اسکیما روی MariaDB با اسکیمای قبلی مقایسه شد (تنها تفاوت: حذف `users.reseller_id`). (۲) Redis واقعی در تست (Cache/Lock/RateLimiter/Queue) با flag قفل‌دار و در CI. (۳) skipهای MariaDB برطرف شد. SQLite و MariaDB+Redis: ۴۸۲ تست سبز.

جزئیات: docs/history/PHASE-7-TEST-ENVIRONMENT.md (بخش «تکمیل فاز ۷»)

---

3.3.4 - فاز ۷ (Full Test Environment): suite روی MariaDB 10.11 هم اجرا شد (۴۷۶ سبز + ۹ skip) و SQLite ۴۸۵ سبز. باگ `resellers.bot_token` (varchar برای مقدار encrypted) در Migration اصلی به text اصلاح شد؛ guard برای همه‌ی ستون‌های encrypted؛ job `tests-mariadb` در CI.

جزئیات: docs/history/PHASE-7-TEST-ENVIRONMENT.md

---

3.3.3 - فاز ۶ (Cleanup): ساختار docs (canonical/operations/history)، VERSION تک‌شماره، حذف کامنت‌های تاریخچه‌ای/کد مرده/import بلااستفاده/هلپر تکراری تست. بدون تغییر رفتار؛ ۴۸۳ تست سبز.

جزئیات: docs/history/PHASE-6-CLEANUP.md

---

3.3.2 - فاز ۵ (Financial Hardening): Integer Minor Unit + ارز قابل‌تنظیم (تومان/دلار/…)

جزئیات: docs/history/PHASE-5-FINANCIAL-HARDENING.md
⚠️ IRREVERSIBLE DATA MIGRATION (2026_10_01_000001) + قفل ارز (system_meta). Backup اجباری. تست‌ها نوشته شدند ولی اجرا نشده‌اند؛
قبل از Tag: php artisan migrate && php artisan test

---

3.3.1 - فاز ۴ (Guest Cleanup): مدل Guest جدید، Email Verification + Gate در Core، Retention ۶۰ روزه، لینک بازگشت به Checkout، اصلاح .env.example

جزئیات: docs/history/PHASE-4-GUEST-CLEANUP.md
⚠️ تست‌ها نوشته شدند ولی اجرا نشده‌اند؛ قبل از Tag: php artisan migrate && php artisan test

---

3.3.0 - Release Website (W0-W8): پاک‌سازی نهایی، Audit و رفع باگ‌های پیدا‌شده در اجرای واقعی تست‌ها

جزئیات: docs/RELEASE-3.3.0-AUDIT.md

نسخه‌ی پایدارسازی است؛ قابلیت جدیدی اضافه نشد. اجرای واقعی php artisan test (کل پروژه):
۴۱۶ تست، ۱۳۹۸ assertion، همه سبز (۹۹ تست Website).

=== باگ‌های واقعی که رفع شد ===
- تداخل مسیرهای پنل نماینده (Filament با path('')) و سایت: GET /login و POST /logout هر دو
  دو بار ثبت می‌شدند؛ لاراول مسیر دوم را با کلید method+uri بی‌صدا جایگزین اولی می‌کند و نام
  route از جدول نام‌ها می‌افتاد → Route [filament.reseller.auth.login/logout] not defined
  (۵۰۰ روی پنل نماینده). ورود پنل نماینده به /panel/login و خروج سایت به /sign-out
  (نام route: website.logout بدون تغییر) منتقل شد.
- ZARINPAL_SANDBOX در config/services.php تعریف شده بود ولی هیچ‌جا خوانده نمی‌شد؛ حالا
  ZarinpalGateway در نبودِ settings.sandbox روش پرداخت، از آن می‌خواند (تست: ZarinpalSandboxConfigTest).
- update-git.sh بعد از git pull هیچ‌وقت npm run build نمی‌زد (public/build در .gitignore است) →
  assetهای جدید ساخته نمی‌شدند و نبودِ manifest هر صفحه‌ی Website را می‌شکست. مرحله‌ی build اضافه شد.
- ExampleTest بدون RefreshDatabase بود (جدول categories وجود نداشت).
- ResolveStoreContext: پارامتر {slug} در route می‌ماند و چون لاراول پارامترها را «به‌ترتیب»
  به کنترلر می‌دهد، جلوتر از {product}/{order} می‌نشست → TypeError/500 روی همه‌ی مسیرهای
  /store/{slug}/... که آرگومان عددی دارند (خرید، صفحه‌ی محصول، مدیریت قیمت). حالا بعد از
  ساخت StoreContext، slug از route حذف می‌شود؛ ResolvesWebsiteRouteNames هم از StoreContext می‌خواند.
- ProvisioningService/AccountService: چهار حلقه‌ی while(true) برای تولید username آزاد؛ اگر پنل
  همیشه «موجود است» می‌گفت تا پرشدن حافظه ادامه می‌یافت. حالا سقف ۱۰۰۰ تلاش + Exception.
- PasswordResetLinkController: برای ایمیل ثبت‌نشده خطای جدا نشان می‌داد (User Enumeration).
  حالا پاسخ همیشه یکسان است؛ فقط محدودیت ۳/ساعت به‌ازای ایمیل خطا می‌دهد.
- routes/website.php: throttle:3,60 روی IP قبل از محدودیت به‌ازای ایمیل، با ۴۲۹ می‌بُرید؛ به 30,60 تغییر کرد.

=== اصلاح تست‌ها (نه باگ محصول) ===
- InteractsWithWebsiteFixtures::fakeSanaeiPanel: کلاینت نمونه 't' پیدا می‌شود، هر username دیگر «ناموجود»
  (همان الگوی PurchaseFlowTest). CheckoutFlowTest و ResellerCommerceFlowTest هم از آن استفاده می‌کنند.
- LazyCustomerAccountCreationTest: forceDelete به‌جای delete (SoftDeletes + unique index).
- ReceiptSecurityTest: UploadedFile::fake نوع فایل را از نام می‌گیرد؛ فایل واقعی ساخته می‌شود.
- ResellerGuestCheckoutTest: GET checkout طبق پچ 3.2.17 حساب نمی‌سازد؛ تست همین را تایید می‌کند.

=== هنوز انجام نشده (نیاز به محیط/شخص واقعی؛ با کد قابل بستن نیست) ===
npm ci && npm run build روی ماشین شما · Migration روی Staging با کپی دیتابیس ·
Zarinpal Sandbox واقعی (ZARINPAL_SANDBOX=true) · Review امنیتی مستقل · تست Backup/Rollback.
راهنمای قدم‌به‌قدم: docs/STAGING-RUNBOOK.md

---

3.2.17 - تصمیم صاحب پروژه: CustomerAccount فقط در لحظه‌ی خرید (override تحلیل نفر 3)

جزئیات: docs/PHASE-W5-PART3-LAZY-CUSTOMER-ACCOUNT.md

EnsureCustomerAccountResolved از resolveCustomerAccount (ساخت) به
findCustomerAccount (فقط خواندن، nullable) تغییر کرد. CheckoutController::store
حالا صریحا resolveCustomerAccount را در لحظه‌ی خرید صدا می‌زند.
AccountsController::renew نیازی به تغییر نداشت (Account از‌قبل‌موجود
همیشه یعنی CustomerAccount هم از قبل هست). بقیه‌ی کنترلرها (Orders،
Accounts index/show، Referral) از قبل null-safe بودند، نیازی به تغییر
نداشتند. هیچ تست موجودی نشکست (همه از قبل CustomerAccount خودشان را
صریح می‌ساختند).

=== تست ===
جدید: tests/Feature/Website/LazyCustomerAccountCreationTest.php

=== اجرا ===
    php artisan test --filter=LazyCustomerAccountCreationTest
    php artisan test --filter=CheckoutFlowTest
    php artisan test --filter=AccountPanelTest
    php artisan test --filter=ResellerCommerceFlowTest
    php artisan test --filter=ResellerManagementTest

بدون PHP نوشته شده؛ نتیجه‌ی واقعی را بفرستید.

---

3.2.16 - فاز W5 (بخش دوم): Audit تکمیلی نفر 3 + یادداشت (سوال امنیتی بعدا توسط شما override شد - ببینید 3.2.17)

جزئیات: docs/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md

Audit کامل 5 بند W5 + 9 بند DoD: همه از قبل پیاده بود، کد جدیدی لازم نشد.
سوال باز «عضویت خودکار با بازدید» توسط نفر 3 تحلیل و «نیازی به تغییر نیست»
نتیجه‌گیری شد - اما این نتیجه‌گیری در پچ 3.2.17 توسط تصمیم صریح شما override شد.

یادداشت ادغام: به‌عنوان 3.2.5 روی مبنای 3.2.4 نوشته شده بود؛ شماره به 3.2.16 تغییر کرد.

---

3.2.15 - فاز W5 (بخش دوم): Guest Checkout نماینده (نفر 1، به درخواست شما)

جزئیات: docs/PHASE-W5-PART2-RESELLER-GUEST-CHECKOUT.md

بررسی زنجیره‌ی کامل کد نشان داد مسیرهای Guest (Shared) از قبل زیر /store/{slug}
درست کار می‌کنند (قیمت customers_price، توکن Reseller-scoped، Redirect به
website.store.*، Debit دوگانه از PurchaseService)؛ پس کد جدیدی اضافه نشد و فقط
تست اضافه شد: tests/Feature/Website/ResellerGuestCheckoutTest.php (7 تست).

اجرا: php artisan test --filter=ResellerGuestCheckoutTest
بدون PHP نوشته شده؛ نتیجه‌ی واقعی را بفرستید.

---

3.2.14 - فاز W5 (نفر 3) بخش اول: Reseller Website - Branding + منوی نماینده + Reseller Management + تست Commerce نماینده

جزئیات: docs/PHASE-W5-PART1-RESELLER-STORE.md

- Branding (بند 46): جدول reseller_website_settings + ResellerWebsiteSetting::brandingFor؛ Layout: --brand پویا،
  لوگو، نام نمایشی، اطلاعات تماس. نماینده‌ی بدون تنظیمات دقیقا مثل قبل دیده می‌شود.
- منوی اختصاصی نماینده (بند 47): فقط برای ادمین همان فروشگاه.
- Reseller Management سبک (بند 49): /store/{slug}/manage/{customers,products,branding} - بدون Business Rule
  در کنترلر؛ همه از ResellerPricingService/ResellerCustomerService/WalletService.
- باگ رفع‌شده هنگام ساخت: Product::customersPrice برای محصول غیرفعال null است.
- تست: ResellerCommerceFlowTest، ResellerBrandingTest، ResellerManagementTest.
- خارج از این پچ: Guest Checkout نماینده؛ یادداشت Review امنیتی درباره‌ی EnsureCustomerAccountResolved.

=== یادداشت ادغام ===
این پچ به‌عنوان 3.2.4 روی مبنای 3.2.3 نوشته شده بود؛ شماره به 3.2.14 تغییر کرد.
VERSION و routes/website.php دستی ادغام شدند (routes: گروه manage فقط زیر
website.store.*، و روی همان گروهی که website.csp دارد).

اجرا: php artisan migrate && php artisan test

---

3.2.13 - فاز W7 + W8: تست، Verification Matrix، Deploy Checklist (نفر 5)

جزئیات کامل: docs/PHASE-W7-W8-TESTING-DEPLOY.md

اسکلت تست مشترک (tests/Concerns/InteractsWithWebsiteFixtures.php، دیر
رسید ولی مستند شد). سه از چهار E2E صریح Roadmap: MainWebsiteE2ETest،
GuestE2ETest، GuestPostPurchaseE2ETest (Reseller E2E مسدود - منتظر
نفر 3). یک گپ واقعی رفع شد: RegisteredUserController حالا ?ref= را
می‌خواند (نکته‌ی باز پچ 3.2.12). Verification Matrix کامل
(docs/VERIFICATION-MATRIX.md) و چک‌لیست Deploy کامل
(docs/PHASE-W8-DEPLOY-CHECKLIST.md).

نتیجه‌ی صادقانه: هیچ ردیفی هنوز TESTED واقعی یا PRODUCTION VERIFIED
نیست - همه منتظر اجرای واقعی php artisan test روی محیط شما هستند.

=== تست ===
جدید: MainWebsiteE2ETest، GuestE2ETest، GuestPostPurchaseE2ETest،
ReferralRegistrationTest.

=== اجرا ===
    php artisan test
(کل مجموعه)

---

3.2.12 - فاز W4 (بخش دوم): Renewal + Referral/Commission (Refund/Retry: Admin-only، بدون تغییر) (نفر 2)

جزئیات کامل: docs/PHASE-W4-PART2-RENEWAL-REFERRAL.md

طبق تصمیم صریح: «همه دقیقا همانند Core و ربات تلگرام». بررسی شد که
هیچ ربات (اصلی/نمایندگی) هرگز دکمه‌ی Refund/Retry به مشتری نشان
نداده؛ پس آن دو Admin-only می‌مانند و همان برچسب وضعیت پچ قبلی
(«نیازمند رسیدگی»/«بازگشت‌شده») برایشان کافی است - هیچ Route/دکمه‌ی
جدیدی برای آن دو اضافه نشد.

در مقابل Renewal یک اکشن واقعی است، چون ربات هم دقیقا همین‌طور است:
Account\AccountsController::renew() سطر‌به‌سطر همان سه بخش
AccountsHandler::renew() ربات (پیش‌بررسی موجودی، صدا زدن
RenewalService، نگاشت سه‌گانه‌ی خطا با همان متن‌های دقیق) را با HTTP
redirect تکرار می‌کند.

Referral/Commission هم اضافه شد: لینک دعوت + تعداد زیرمجموعه (سطح
User)، و فهرست کمیسیون‌های پرداخت‌شده که Context-scoped است
(referrer_customer_account_id).

نکته‌ی باز (خارج از مالکیت این پچ): ثبت‌نام سایت هنوز ?ref= را نمی‌خواند.

=== تست ===
جدید: tests/Feature/Website/AccountPanelPart2Test.php - تمدید موفق
(کسر کیف‌پول + تمدید انقضا)، پیام دقیقا هم‌متن با ربات برای موجودی
ناکافی (بدون تماس با پنل)، مالکیت (404 برای اکانت دیگری)، لینک/تعداد
زیرمجموعه، ایزولاسیون Context در فهرست کمیسیون.

=== اجرا ===
    php artisan test --filter=AccountPanelPart2Test

بدون Migration جدید.

=== یادداشت ادغام ===
این پچ اصلا به‌عنوان نسخه‌ی 3.2.5 روی مبنای 3.2.4 (نسخه‌ی اول نفر 2)
نوشته شده بود. چون پچ‌های 3.2.5 تا 3.2.11 قبلا توسط من (نفر 1، نفر 4،
و ادغام قبلی نفر 2) گرفته شده بودند، شماره به 3.2.12 تغییر کرد. فقط
VERSION و routes/website.php نیاز به ادغام دستی داشتند.

---

3.2.11 - فاز W4 (بخش اول): پنل کاربری - Wallet + Orders + Accounts (نفر 2)

جزئیات کامل: docs/PHASE-W4-PART1-ACCOUNT-PANEL.md

فقط آیتم‌های 1 تا 3 از هفت‌بند فاز W4 (نفر 2: پنل کاربری). صفحه‌ی
Wallet (موجودی + گردش حساب + لینک شارژ موجود)، فهرست کامل سفارش‌ها،
فهرست و جزئیات اکانت‌های VPN - همه Context-isolated و صرفا نمایشی.

Renewal، Referral/Commission، Refund UI، Retry UI (آیتم‌های 4 تا 7)
عمدا در این پچ نیستند: هرکدام به یک تصمیم معماری صریح نیاز دارند
(نگاشت خطا، یا اینکه اصلا کاربر عادی حق درخواست Refund/Retry دارد یا
نه). جزئیات در مستند بالا.

=== ادغام با کار نفر 1 و نفر 4 (Merge Note) ===
این پچ اصلا به‌عنوان "3.2.4" روی نسخه‌ی 3.2.3 نوشته شده بود (قبل از
اینکه پچ‌های 3.2.4 تا 3.2.10 من (نفر 1: هویت مهمان + نفر 4: امنیت)
منتشر شوند)، پس شماره‌ی نسخه به 3.2.11 (شماره‌ی واقعی بعدی در توالی)
تغییر کرد. محتوای کد بدون تغییر منطقی اعمال شد؛ تنها دو فایل نیاز به
ادغام دستی داشتند (VERSION همین‌جا، و routes/website.php - چون هر دو
فایل مشترکی هستند که چند نفر به آن‌ها append می‌کنند). هیچ تداخل
واقعی‌ای در منطق برنامه پیدا نشد: پوشه‌ی Controllers/Account/ کاملا
جدا از Controllers/Guest/ و Controllers/Identity/ (نفر 1) و
Http/Middleware/ (نفر 4) است.

=== تست ===
جدید: tests/Feature/Website/AccountPanelTest.php - دسترسی
guest->login، موجودی/گردش‌حساب صحیح و ایزوله، فهرست سفارش‌ها ایزوله
بین Main و Reseller Context، فهرست و جزئیات اکانت با 404 برای مالکیت
نادرست.

=== اجرا ===
    php artisan test --filter=AccountPanelTest

بدون Migration جدید.

---

3.2.10 - فاز W6 (بخش پنجم): Review امنیتی Telegram-linking (نفر 4، W6 کامل شد)

جزئیات کامل: docs/PHASE-W6-PART5-TELEGRAM-REVIEW.md

Self-audit روی پچ 3.2.5 (با محدودیت صادقانه: من هم سازنده هم مرورگرم،
Review واقعا مستقل نیست). یافته‌ی اصلی: Login/Link CSRF - یک مهاجم
می‌توانست callback معتبر خودش را برای قربانی بفرستد و تلگرام خودش را
به حساب قربانی وصل کند. رفع شد با یک state یک‌بارمصرف Session-bound.
بقیه‌ی چک‌لیست (HMAC واقعی، hash_equals، auth_date، بدون fallback
ناامن، بدون merge خودکار، rate limit، پشت auth) تایید شد بدون مشکل.

=== تست ===
tests/Feature/Website/TelegramLinkingTest.php: 3 تست جدید (بدون state،
state نامنطبق، replay) + تست‌های قبلی به‌روز شدند.

=== اجرا ===
    php artisan test --filter=TelegramLinkingTest
    php artisan test --filter=AuditLoggingTest
بدون PHP نوشته شده؛ نتیجه واقعی را بفرستید. توصیه: یک Review واقعا
مستقل قبل از Production.

=== وضعیت فاز W6 ===
با این پچ، پنج بند فاز W6 (Rate Limiting، CSP، Receipt Storage، Audit،
Telegram Review) کامل شد. نقش نفر 4 طبق بخش 10 تمام شد.

---

3.2.9 - فاز W6 (بخش چهارم): Audit (نفر 4)

جزئیات کامل: docs/PHASE-W6-PART4-AUDIT.md

Payment Confirmation از قبل رایگان پوشش داده شده بود (PaymentService
از قبل AuditService را صدا می‌زند). Refund request: N/A (Website هنوز
Refund ندارد). گپ واقعی: AuditService::resolveActor فقط Admin/Reseller
می‌شناخت. اضافه شد: User (guard web) -> actor_type='customer'. Migration
جدید: audit_logs.actor_type از ENUM به string. سه نقطه‌ی Audit جدید:
identity.telegram_linked، identity.telegram_link_rejected_owned_by_other،
identity.guest_account_created، identity.guest_collision_detected.

=== تست ===
جدید: tests/Feature/Website/AuditLoggingTest.php

=== اجرا ===
    php artisan migrate
    php artisan test --filter=AuditLoggingTest
    php artisan test --filter=PaymentServiceTest
بدون PHP نوشته شده؛ نتیجه واقعی را بفرستید.

---

3.2.8 - فاز W6 (بخش سوم): Receipt Upload Security (نفر 4)

جزئیات کامل: docs/PHASE-W6-PART3-RECEIPT-SECURITY.md

Storage خصوصی + سرو کنترل‌شده از پچ 3.2.2 از قبل درست بود. این پچ:
سقف حجم 4MB به 5MB، pdf اضافه شد (طبق بخش 9.6)، و دو لایه‌ی دفاعی
جدید در TelegramReceiptController: Content-Type allow-list +
X-Content-Type-Options: nosniff (دفاع در برابر Stored XSS).

=== تست ===
جدید: tests/Feature/Website/ReceiptSecurityTest.php

=== اجرا ===
    php artisan test --filter=ReceiptSecurityTest
    php artisan test --filter=WalletChargeFlowTest
بدون PHP نوشته شده؛ نتیجه واقعی را بفرستید.

---

3.2.7 - فاز W6 (بخش دوم): Rate Limiting (نفر 4)

جزئیات کامل: docs/PHASE-W6-PART2-RATE-LIMITING.md

Audit نشان داد Login و Checkout از قبل درست بودند (throttleKey موجود،
throttle:10,1 موجود). فقط دو مورد اصلاح شد: Upload رسید از 10/دقیقه
به 5/دقیقه (طبق بخش 9.6)، و Reset رمز حالا واقعا per-email است (قبلا
فقط per-IP بود که طبق سند اشتباه بود).

=== تست ===
جدید: tests/Feature/Website/RateLimitingTest.php

=== اجرا ===
    php artisan test --filter=RateLimitingTest
بدون PHP نوشته شده؛ نتیجه واقعی را بفرستید.

---

3.2.6 — فاز W6 (بخش اول): CSP Header (نفر ۴)

جزئیات کامل: docs/PHASE-W6-PART1-CSP.md

از این پچ نقش نفر ۴ (امنیت و سخت‌سازی) را ایفا می‌کنم. طبق توصیه‌ی
Roadmap، هر بند W6 پچ جدا: این یکی فقط CSP.

=== تغییرات ===
- SetContentSecurityPolicyHeader middleware (alias website.csp، ثبت در
  bootstrap/app.php).
- فقط روی دو گروه Route Website (Main+Reseller) در routes/website.php،
  نه سراسری روی 'web' (پنل ادمین/نماینده دست‌نخورده می‌ماند).
- سازش آگاهانه: style-src 'unsafe-inline' چون Viewهای موجود از
  style="..." inline برای رنگ Brand استفاده می‌کنند؛ حذفش نیاز به
  هماهنگی با نفرات ۱-۳ دارد، کار آینده.

=== تست ===
جدید: tests/Feature/Website/ContentSecurityPolicyTest.php

=== اجرا ===
    php artisan test --filter=ContentSecurityPolicyTest
بدون PHP نوشته شده؛ نتیجه‌ی واقعی را بفرستید.

---

3.2.5 — فاز W3 (بخش سوم): Telegram-linking واقعی (نفر ۱، W3 کامل شد)

جزئیات کامل: docs/PHASE-W3-PART3-TELEGRAM-LINKING.md

HMAC واقعی طبق الگوریتم رسمی Telegram Login Widget (نه HMAC جعلی مثل
VPNMarket): secret واقعی از bot_token، hash_equals ثابت‌زمانی، چک
auth_date (max 24h). عمداً «Linking» است نه «Login» — پشت auth، طبق
متن دقیق Roadmap. حتی با امضای معتبر، اگر آن تلگرام قبلاً به کاربر
دیگری وصل است، merge خودکار نمی‌شود (بند ۸۷ سند مادر).

=== تغییرات ===
- TelegramLoginVerifier (Support/) — منطق HMAC خالص.
- TelegramLinkController (Controllers/Identity/) — GET
  /identity/telegram/callback، پشت auth+store.customer، throttle:20,1.
- ویجت رسمی تلگرام در complete-profile.blade.php (فقط Main Context —
  نمایندگان نیاز به ستون bot_username دارند که هنوز نیست).

=== تست ===
جدید: tests/Feature/Website/TelegramLinkingTest.php — لینک موفق، hash
دستکاری‌شده، auth_date منقضی، تلگرام متعلق به کاربر دیگر، رد دسترسی مهمان.

=== اجرا ===
    php artisan test --filter=TelegramLinkingTest
باز هم بدون PHP نوشته شده؛ نتیجه‌ی واقعی اجرا را بفرستید.

=== وضعیت فاز W3 ===
با این پچ، شش‌بند فاز W3 (طبق Roadmap) کامل شد. طبق بخش ۱۰، قدم بعدی
نفر ۴ (Review امنیتی Telegram-linking) است، نه من — مگر دستور دیگری
بدهید.

---

3.2.4 — فاز W3 (بخش دوم): تکمیل خرید مهمان + مالکیت پوشه‌ها (نفر ۱)

جزئیات کامل: docs/PHASE-W3-PART2-GUEST-PURCHASE.md

از این پچ به بعد طبق بخش ۱۰ Roadmap («تقسیم کار بین ۵ نفر») کار می‌کنم:
نفر ۱ (هویت مهمان + اتصال تلگرام)، فقط مالک Controllers/Guest/،
Controllers/Identity/، views/website/guest/، views/website/identity/.

=== تغییرات ===
- Refactor بدون تغییر رفتار: GuestCheckoutController و ویوهایش از
  Controllers/Shared به Controllers/Guest منتقل شدند.
- views/website/guest/entry-link.blade.php — Partial قابل‌استفاده‌ی
  مجدد برای نفر ۳ (طبق وابستگی بند ۱۷ Roadmap)، منتشر شده زود.
- GuestPurchaseController (بند ۳ W3): مهمان → User ناقص (بدون رمز
  عبور) → Auth::login → همان CheckoutController/ChargeController
  تست‌شده‌ی پچ ۳.۲.۱/۳.۲.۲. تصمیم کامل و جایگزین‌های بررسی‌شده در سند.
- Identity Resolution (بند ۴): پیش از خرید، فقط رد یا هدایت به Login —
  هرگز merge خودکار (بند ۸۷ سند مادر).
- CompleteProfileController (بند ۵، نسخه‌ی محدود): تنظیم رمز عبور برای
  User ناقصِ برآمده از خرید مهمان. لینکش در order-show.blade.php.

=== تست ===
جدید: tests/Feature/Website/GuestPurchaseFlowTest.php.

=== اجرا ===
    php artisan test --filter=GuestPurchaseFlowTest
    php artisan test --filter=GuestCheckoutTokenTest
    php artisan test --filter=CheckoutFlowTest
باز هم بدون PHP نوشته شده؛ نتیجه‌ی واقعی اجرا را بفرستید.

=== فاز بعدی ===
پچ ۳.۲.۵: Telegram-linking واقعی (HMAC معتبر) — عمداً پچ جدا، چون
Security-critical است و نمونه‌ی مرجع در همین بخش ناامن بود.

---

3.2.3 — فاز W3 (بخش اول): Guest Checkout Token

جزئیات کامل: docs/PHASE-W3-PART1-GUEST-CHECKOUT-TOKEN.md

فقط بند ۱ از شش‌بند فاز W3 (بخش ۹.۳). جدول guest_checkouts +
GuestCheckoutService (در Core، نه کانال Website) + Endpoint عمومی
(بدون auth) برای شروع خرید مهمان با نام/تلفن/ایمیل، توکن در Cookie
امضاشده (EncryptCookies، بدون HMAC دستی)، TTL=۴۵ دقیقه.

تکمیل پرداخت/سفارش واقعی برای Guest عمداً در این پچ نیست: نیاز به یک
تصمیم معماری صریح دارد چون PurchaseService::purchase() امروز
CustomerAccount الزامی می‌گیرد. جزئیات در مستند بالا.

=== تست ===
جدید: tests/Feature/Website/GuestCheckoutTokenTest.php — شروع بدون
auth، الزامی‌بودن نام/تلفن، خواندن Cookie، توکن نامعتبر/منقضی، ایزوله
Main/Reseller.

=== اجرا ===
    php artisan migrate
    php artisan test --filter=GuestCheckoutTokenTest
باز هم بدون PHP نوشته شده؛ نتیجه‌ی واقعی اجرا را بفرستید.

=== فاز بعدی ===
بند ۳ فاز W3: تصمیم اتصال Guest به PurchaseService + تکمیل Flow تا
Provisioning.

---

3.2.2 — فاز W2 (بخش دوم): شارژ کیف‌پول با Zarinpal + Card-to-Card

جزئیات کامل: docs/PHASE-W2-PART2-PAYMENT-METHODS.md

ادامه‌ی بی‌واسطه‌ی 3.2.1. Zarinpal/Card-to-Card فقط کیف‌پول را شارژ
می‌کنند (PaymentService::initiate فقط purpose=wallet_charge می‌پذیرد)؛
تکمیل خرید هم‌چنان با برگشت کاربر به Checkout انجام می‌شود، نه ادامه‌ی
خودکار.

=== تغییرات ===
- WebsiteChargeFacade (Adapter روی PaymentService::initiate).
- ChargeController: GET/POST /wallet/charge (انتخاب مبلغ/روش؛ Zarinpal
  redirect، Card-to-Card به صفحه‌ی رسید).
- ReceiptController: GET/POST /wallet/charge/{payment}/receipt، مالکیت
  با همان Payment::findPendingForReceipt ربات.
- تغییر کد مشترک (خارج از کانال Website): TelegramReceiptController
  حالا هم file_id تلگرام و هم رسید آپلودشده‌ی سایت (پیشوند website:،
  دیسک خصوصی local) را نمایش می‌دهد.
- لینک «شارژ کیف پول» در checkout-show.blade.php جایگزین placeholder شد.

=== تست ===
جدید: tests/Feature/Website/WalletChargeFlowTest.php — ریدایرکت
Zarinpal (Http::fake)، مسیر Card-to-Card تا آپلود رسید، عدم دسترسی به
رسید/پرداخت کاربر دیگر.

=== اجرا ===
    php artisan route:list --name=website
    php artisan test --filter=WalletChargeFlowTest
    php artisan test --filter=PaymentServiceTest
باز هم بدون PHP نوشته شده؛ نتیجه‌ی واقعی اجرا را بفرستید. علاوه بر
این، چون TelegramReceiptController (کد مشترک) تغییر کرده، حتماً
PaymentServiceTest و هر تست دیگری که آن Endpoint را پوشش می‌دهد هم
اجرا و چک شود.

=== فاز بعدی ===
W2 عملاً کامل شد. قدم بعدی: W3 (Guest Checkout).

---

3.2.1 — فاز W2 (بخشی): Checkout بدون Cart + Wallet Payment + نمایش سفارش

جزئیات کامل: docs/PHASE-W2-COMMERCE-CORE.md

ادامه‌ی بی‌واسطه‌ی 3.2.0 (W0-W1). زنجیره‌ی حداقلی قابل‌فروش را کامل
می‌کند: Product → Checkout → Wallet Payment → Order Display. فقط
پرداخت کیف‌پول؛ Zarinpal و Card-to-Card عمداً در این پچ نیستند (دلیل
در سند بالا). Guest Checkout و فهرست کامل سفارش‌ها به‌ترتیب فازهای W3
و W4 می‌مانند.

=== تغییرات ===
- WebsitePurchaseFacade (Adapter نازک روی PurchaseService::purchase،
  هم‌الگو با WebsiteCatalogFacade/WebsiteWalletFacade).
- CheckoutController: GET صفحه‌ی تایید (توکن Idempotency تازه هر بار)،
  POST اجرای خرید؛ throttle:10,1 روی POST.
- OrderController::show: نمایش تک‌سفارش + وضعیت Provisioning، با چک
  مالکیت (customer_account_id) و Context (reseller_id).
- Route/View جدید برای Main و Reseller (همان الگوی registerSharedRoutes
  موجود)؛ فعال‌سازی دکمه‌ی خرید در product-show.blade.php که در ۳.۲.۰
  عمداً placeholder بود.

=== تست ===
جدید: tests/Feature/Website/CheckoutFlowTest.php — مهمان→ریدایرکت
login، خرید موفق کیف‌پول، Idempotency (دو بار ارسال همان توکن = یک
خرید)، موجودی ناکافی، عدم دسترسی به سفارش کاربر دیگر.

=== اجرا ===
    php artisan route:list --name=website
    php artisan test --filter=CheckoutFlowTest
این پچ هم بدون PHP نوشته شده؛ نتیجه‌ی واقعی php artisan test را
بفرستید — دقیقاً همان محدودیتی که پچ ۳.۲.۰ (W0-W1) هم داشت.

=== فاز بعدی ===
باقی‌مانده‌ی W2: Zarinpal (Direct Payment) و Card-to-Card. سپس W3
(Guest Checkout) طبق ترتیب Roadmap.

---

3.1.8 — فاز A3 (سند v2.1، بند ۶۹) کامل شد: اقلام ۸ تا ۱۰ (Retry/Duplicate Retry/عدم Debit مجدد)

جزئیات کامل: docs/PHASE-A3-PART3-RETRY-TESTS.md

سه قلمِ آخرِ فازِ A3. بندِ ۷۵ (retry بدون Debit مجدد) و بندِ ۲۵/فاز ۱۱
(claimForRetry به‌عنوان قفلِ اتمیکِ ضدِ retry تکراری) از قبل در کد
پیاده‌سازی شده بودند؛ این پچ چیزی در منطقِ کسب‌وکار عوض نمی‌کند، فقط سه
تستِ جدید اضافه می‌کند که آن رفتار را مستقیماً روی رکوردهایِ
ProvisioningAttempt هم verify می‌کنند (چیزی که FailurePolicyTest، چون
قبل از خودِ جدولِ provisioning_attempts نوشته شده بود، پوشش نمی‌داد).

=== تغییرات ===
هیچ تغییری در کدِ Production نیست — فقط تست:
- tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php (فایلِ
  موجود، نه جدید):
  - retrying_a_failed_order_succeeds_and_records_the_next_attempt_without_a_new_debit
    (اقلامِ ۸ و ۱۰): خرید با شکستِ پنل → موجودی دقیقاً main_price کم
    می‌شود → retryProvisioning موفق → دو ProvisioningAttempt
    (failed سپس succeeded) → موجودی بعدِ retry دقیقاً همان مقدارِ
    قبل از retry (بدون کسرِ دوم).
  - a_concurrent_duplicate_retry_is_rejected_without_a_new_attempt_or_debit
    (قلمِ ۹): سفارش با claimForRetry مستقیم «claim» می‌شود (شبیه‌سازیِ
    فرآیندِ اول)، سپس retryProvisioning تکراری روی همان سفارش
    PurchaseNotAllowedException می‌دهد؛ نه ProvisioningAttempt جدیدی
    ساخته می‌شود، نه موجودی تغییر می‌کند.

=== اجرا ===
    php artisan test tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php

با این پچ، فازِ A3 سند v2.1 (بندِ ۶۹) کاملاً پیاده و تست شده است.

---

3.1.7 — فاز A3 (سند v2.1، بند ۶۹)، اقلام ۵ تا ۷: error/started_at/finished_at

جزئیات کامل: docs/PHASE-A3-PART2-ERROR-AND-TIMESTAMPS.md

ادامه‌ی بی‌واسطه‌ی 3.1.6 (که فقط اقلامِ ۱ تا ۴ را پیاده کرده بود). این پچ سه
ستونِ باقی‌مانده‌ی ProvisioningAttempt را اضافه می‌کند: error، started_at،
finished_at. اقلامِ ۸ تا ۱۰ (تستِ Retry/Duplicate Retry و اطمینانِ صریح از
عدمِ Debit مجدد) همچنان برای فاز بعدی می‌مانند.

=== تغییرات ===
- Migration جدیدِ ALTER (نه ویرایشِ Migration قبلی، چون آن یکی احتمالاً
  از قبل روی سیستمِ شما اجرا شده): جدولِ provisioning_attempts سه ستونِ
  nullable گرفت: error (text)، started_at (timestamp)، finished_at
  (timestamp).
- ProvisioningAttempt: سه فیلد به fillable اضافه شد؛ started_at و
  finished_at در casts به datetime تبدیل شدند.
- ProvisioningService::beginAttempt(): حالا started_at را هم موقعِ ساختِ
  رکورد می‌نویسد.
- مسیرِ موفقِ provision(): finished_at را هم‌زمان با status=succeeded
  می‌نویسد.
- ProvisioningService::recordFailure(): همان reason که تا امروز فقط روی
  orders.failure_reason می‌رفت، حالا (بعد از یک mb_substr مشترک) روی
  attempt.error هم نوشته می‌شود — چون failure_reason با هر retry جدید
  بازنویسی می‌شود و تاریخچه‌ی شکستِ تلاش‌های قبلی را پاک می‌کند.
  finished_at هم همان لحظه ثبت می‌شود.

=== تست ===
tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php به‌روزرسانی
شد (نه فایلِ جدید): بررسیِ error=null و started_at/finished_at پر برای
تلاشِ موفق؛ بررسیِ error غیرِ null و finished_at پر برای دو مسیرِ شکست.

=== اجرا ===
    php artisan migrate
    php artisan test tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php
این پچ هم بدون PHP نوشته شده؛ نتیجه‌ی واقعیِ php artisan test را بفرستید.

=== فاز بعدی ===
باقی‌مانده‌ی فازِ A3: تستِ Duplicate Retry و اطمینانِ صریح از عدمِ Debit
مجدد در سطحِ کیف‌پول (اقلامِ ۸ تا ۱۰).

---

3.1.6 — فاز A3 (سند v2.1، بند ۶۹)، اقلام ۱ تا ۴: Provisioning Attempt Tracking

جزئیات کامل: docs/PHASE-A3-PROVISIONING-ATTEMPT-TRACKING.md

این پچ فقط چهار قلمِ اولِ فازِ A3 را پیاده می‌کند (طبق درخواستِ فعلی):
ایجادِ مدلِ ProvisioningAttempt و ثبتِ operation_id، attempt_number و
status برای هر تلاش. ستون‌های error/started_at/finished_at و تست‌های
Retry/Duplicate Retry/عدمِ Debit مجدد (اقلامِ ۵ تا ۱۰) عمداً در این پچ
نیستند و برای فاز بعدی می‌مانند.

=== تغییرات ===
- Migration جدید: جدولِ provisioning_attempts (order_id، operation_id
  nullable، attempt_number، status، timestamps).
- مدلِ جدید App\Models\ProvisioningAttempt (+ Factory).
- Order::provisioningAttempts() — رابطه‌ی hasMany جدید.
- ProvisioningService::provision(): برای هر تلاشِ واقعی (چه موفق، چه
  ناموفق، چه «بدون پنلِ در دسترس») یک ردیفِ ProvisioningAttempt با
  attempt_number برابرِ orders.provision_attempts در همان لحظه، و
  operation_id از همان Operation-ی که فراخوان (Purchase/retry) با خود
  آورده، ساخته می‌شود. وضعیت با موفقیت/شکستِ همان تلاش به succeeded/failed
  به‌روزرسانی می‌شود.
- ProvisioningService::recordFailure(): پارامترِ اختیاریِ $attempt گرفت؛
  اگر تماس‌گیرنده آن را نداشته باشد (مسیرِ کاتچِ استثنای غیرمنتظره‌ی
  PurchaseService::retryProvisioning)، آخرین تلاشِ started همان سفارش را
  خودش پیدا و failed می‌کند — تا هیچ تلاشی بدون نتیجه در started نماند.
- اثرِ جانبیِ کوچک: شاخه‌ی «بدون پنلِ در دسترس» تا امروز $operation را به
  recordFailure پاس نمی‌داد (ناهماهنگ با سه شاخه‌ی دیگر)؛ همین‌جا هم‌راستا
  شد.

=== تست ===
جدید: tests/Feature/Provisioning/ProvisioningAttemptTrackingTest.php —
تلاشِ موفق (succeeded + operation_id درست)، شکستِ پاسخِ پنل، نبودِ پنلِ
در دسترس، و یک retry که تلاشِ دومِ succeeded با attempt_number=2 می‌سازد.

=== اجرا ===
    php artisan migrate
    php artisan test
این پچ بدون PHP نوشته شده؛ نتیجه‌ی واقعی php artisan test را بفرستید.

=== فاز بعدی ===
باقی‌مانده‌ی فاز A3: ستون‌های error/started_at/finished_at روی
provisioning_attempts، تستِ Duplicate Retry، و اطمینانِ صریح از اینکه
retry هیچ Debit جدیدی نمی‌سازد (اقلامِ ۵ تا ۱۰).

---

3.1.5 — فاز A2 (سند v2.1 Phase 2): Financial Concurrency — Sale Limit + Capacity

جزئیات کامل: docs/PHASE-A2-FINANCIAL-CONCURRENCY.md
(از این پچ به بعد، شماره‌ی Phase خودِ سند با پیشوند A می‌آید: Phase 2 سند → فاز A2،
تا با شماره‌گذاریِ فازهای پروژه قاطی نشود.)

=== باگ‌های واقعی که پیدا و رفع شد ===
1) Sale Limit با یک COUNT بدون قفل، پیش از شروع تراکنش چک می‌شد — دو خرید هم‌زمان
   هر دو COUNT قدیمی را می‌دیدند و هر دو رد می‌شدند (Oversell).
2) همان COUNT حتی مستقل از Race هم ناقص بود: وضعیت provision_failed را نمی‌شمرد —
   سفارشی با پول‌گرفته‌شده و Provisioning‌ِ گیرکرده، سهمیه را «آزاد» نشان می‌داد.
3) Capacity (ستون server_panels.capacity) اصلاً enforce نمی‌شد؛ ادمین تنظیمش
   می‌کرد ولی هیچ کوئری‌ای نمی‌خواندش. active_accounts_count هم فقط بعد از ساختِ
   موفقِ اکانت افزایش می‌یافت — دقیقاً همان پنجره‌ی Race.

=== راه‌حل ===
Atomic Counter (UPDATE شرطیِ تک‌دستور)، نه Row Lock/Cache Lock — تنها گزینه‌ای که
در Test Suite تک‌پردازه‌ی این پروژه (SQLite :memory:، CACHE_STORE=array) واقعاً
قابل اثبات بود. جزئیات کامل در سند بالا.

=== تغییرات ===
- Migration: products.units_sold با Backfill معنایی از سفارش‌های موجود.
- PurchaseGuard::assertSaleLimitNotReached بازنویسی شد (رفع باگ #2 هم همین‌جا).
- PurchaseService::execute: رزروِ اتمیک درون تراکنشِ مالی، پیش از هر Debit.
- RefundService::execute: آزادسازی سهمیه برای سفارشِ غیرِ-تمدید.
- ServerPanel::reserveCapacitySlot()/releaseCapacitySlot()؛ ServerSelectionStrategy
  متدِ جدیدِ selectAndReserve() گرفت؛ select() هم حالا ظرفیت را می‌سنجد.
- ProvisioningService::provision و AccountService (مسیر اکانت تست) هر دو به
  select-and-reserve-قبل‌از-تماسِ-پنل منتقل شدند؛ آزادسازی فقط اگر پاک‌کردنِ
  اکانتِ یتیم از پنل هم موفق شود.
- اثرِ جانبی که رفع شد: PurchaseGuard::assertServerAvailable حالا manualPanel
  می‌گیرد (وگرنه پنلِ دستی‌انتخاب‌شده می‌توانست به‌غلط رد شود).
- اثرِ جانبیِ مهم‌تر: RenewalService این چک را با checkServerAvailability:false
  خاموش می‌کند — وگرنه تمدید روی یک دسته‌بندیِ پر (نه خودِ سرورِ اکانت) رد می‌شد.
- ARCHITECTURE.md بخش ۱۱ (جدید) + رفعِ یک تصادفِ شماره‌گذاریِ بخش از پچ فاز ۱.

=== تست ===
جدید: tests/Feature/Concurrency/SaleLimitConcurrencyTest.php (۶ تست)،
tests/Feature/Concurrency/ServerCapacityConcurrencyTest.php (۸ تست).

=== اجرا ===
    php artisan migrate
    php artisan test
این پچ بدون PHP نوشته شده؛ نتیجه‌ی واقعی php artisan test را بفرستید.

=== فاز بعدی ===
Phase A3 سند — Provisioning Attempt Tracking (بند ۶۹): مدلِ مستقلِ ProvisioningAttempt.

---
3.1.4 — فاز A۱ سند v2.1: Payment Foundation (State Machine + Purpose + Wallet/Direct Payment)

جزئیات کامل: docs/PHASE-A1-PAYMENT-FOUNDATION.md

=== باگ واقعی که پیدا و رفع شد ===
reject() و rejectByReseller() برخلاف finalize()/refund() هیچ lockForUpdate/تراکنشی
نداشتند. اگر یک ادمین دقیقاً هم‌زمان با تاییدشدنِ یک پرداخت (مثلاً callback زرین‌پال)
روی «رد» می‌زد، status بی‌صدا به rejected برمی‌گشت در حالی که کیف‌پول از قبل شارژ شده
بود. رفع شد: هر دو حالا مثل finalize()/refund() قفل و تراکنش دارند.

=== تغییرات ===
- PaymentStateMachine (که از قبل وجود داشت) اکنون تنها مرجع نوشتن وضعیت پرداخت است؛
  هر چهار متد PaymentService (finalize/reject/rejectByReseller/refund) از طریق
  transition() عبور می‌کنند، نه $payment->update(['status'=>...]) مستقیم.
  دو تلاش هم‌زمان روی یک پرداخت حالا با InvalidPaymentTransitionException می‌بندد.
- refund() حالا Audit (payment.refunded) هم ثبت می‌کند (قبلاً نداشت).
- چهار نقطه‌ی تماس (ربات نماینده، دو پنل Filament، callback زرین‌پال) با استثنای
  جدید (RuntimeException، نه LogicException) سازگار شدند؛ دکمه‌ی «رد» در پنل ادمین
  اصلاً try/catch نداشت — اضافه شد.
- Purpose (order/wallet_charge) و مرز Wallet Payment در برابر Direct Payment در
  ARCHITECTURE.md بخش ۱۰ (جدید) صریح مستند شد؛ هر دو از قبل در کد enforce بودند.

=== خارج از Scope این فاز (تصمیم آگاهانه) ===
«Direct Payment → Purchase» (پرداخت مستقیم برای یک سفارش مشخص، بدون توقف در
Wallet) یک قابلیت جدید و مستقل است که اصلاً وجود ندارد، نه یک نقض موجود؛ ساختنش
از پایه در این پچ نبود. جزئیات و پیش‌نیازهای تصمیم در سند بالا.

=== تست ===
جدید: tests/Feature/Payments/PaymentStateMachineTest.php (سه گذار مجاز + هشت گذار
نامعتبر، با DataProvider). اضافه‌شده به PaymentServiceTest.php: تایید دوباره،
رد بعد از تایید (شبیه‌سازی Race)، تایید بعد از رد، بازگشتِ دوباره‌ی وجه،
webhook تکراری (بدون double-charge و بدون verify دوباره).

=== اجرا ===
Migration ندارد.
    php artisan test
این پچ بدون PHP نوشته شده؛ نتیجه‌ی واقعی php artisan test را بفرستید.

=== فاز بعدی ===
Phase A2 سند — Financial Concurrency: Sale Limit (بند ۶۱) و Capacity (بند ۶۵)
هر دو نیازمند Reservation/Row-Lock در برابر خرید هم‌زمان.

---

3.1.3 — پاک‌سازی و سبک‌سازی (پیش از فازهای سند v2.1)

جزئیات و Inventory کامل: docs/CLEANUP-3.1.3-INVENTORY.md

تحلیل ایستای کل پروژه (متد/کلاس/ثابت/import/View/Config بلااستفاده) انجام شد؛ پروژه از قبل تمیز بود و
فقط موارد «قطعاً بلااستفاده» حذف شد: Controller/ExampleTest/دو Factory boilerplate یا بلااستفاده، صفحه‌ی
پیش‌فرض Laravel (با صفحه‌ی مینیمال جایگزین شد)، ۱۲ متد مرده (از جمله OperationService::dueForRetry،
Payment::walletOwner و زمان‌بندی available_at که هیچ‌کس مصرف نمی‌کرد)، دو import، و چند کامنت منسوخ.
README به update-git.sh اشاره می‌کند و branch منسوخ CI حذف شد. هیچ Migration ندارد.

رفع باگِ تست: چهار شکست باقی‌مانده‌ی FailurePolicyTest ناشی از dirty-check دقت-ثانیه‌ی Eloquent روی مدل
کهنه در تست بود (نه باگ retry)؛ helper makeDue() با UPDATE مستقیم.

⚠️ امنیت: ZIP اشتراک‌گذاری‌شده شامل webhook.json (توکن و secret واقعی ربات)، .env و بکاپ SQL بود.
توکن‌ها/secretها/رمزها را Rotate کنید — مراحل در بخش ۶ سند بالا.

=== اجرا ===
    php artisan test
انتظار: همه سبز (suite «Unit» حذف شد؛ تعداد کل تست‌ها یکی کمتر می‌شود).
این پچ بدون اجرای PHP نوشته شده؛ نتیجه‌ی php artisan test را بفرستید.

=== بعد از این ===
فازهای سند v2.1 به ترتیب اولویت: Phase 1 (Payment State Machine + تفکیک Wallet/Direct Payment)، سپس Concurrency (Sale Limit/Capacity)...

---

Stage 15 (سند بند ۵۹) — Full Test Audit

جزئیات: docs/STAGE-15-FULL-TEST-AUDIT.md

php artisan test → ۹ شکست از ۲۸۰. پنج مورد رفع شد: Filament پنل نماینده
(ownershipRelationship اشتباه به دلیل حذف User::reseller() در فاز ۱۵)،
دو تست با انتظار بازگشت خودکار وجه که دیگر پیش‌فرض نیست (ResellerPriceFieldTest،
ResellerPurchaseFinancialTest)، یک نوع استثنای قدیمی (ResellerDebtLimitException
به‌جای InsufficientBalanceException، طبق بند ۴۶)، و BroadcastService که
صاحبِ یک نماینده‌ی بدون‌سابقه را هم مخاطب Main حساب می‌کرد.

باز مانده: چهار تست در FailurePolicyTest (دومین retry خودکار
provision_attempts را افزایش نمی‌دهد) — نیازمند اجرای واقعی با دیباگ،
جزئیات در سند.

=== اجرا ===
    php artisan test

---

تصمیم بند ۱۸ ↔ Rule 2/13 — خرید شخصی صاحب نماینده از Main

جزئیات: docs/DECISION-CLAUSE-18-RESELLER-OWN-PURCHASE.md

تصمیم: وقتی خودِ صاحبِ یک نماینده شخصاً از فروشگاه اصلی خرید می‌کند،
Context همچنان main می‌ماند (Rule 13)، ولی مبلغ reseller_price است، نه
main_price (بند ۱۸). یک مشتری عادی بدون تغییر همچنان main_price
می‌پردازد.

پیاده‌سازی: PriceSnapshot::forMainStore() یک پارامتر $buyerOwnsAReseller
گرفت؛ PurchaseService::execute() و RenewalService::execute() هر دو با
$customer->user->resellerAccount()->exists() این حالت را تشخیص می‌دهند.
تست Skipped فاز ۱۴ (FinalModelSpecTest::open_decision_...) به تست واقعی
a_reseller_owner_buying_directly_from_main_pays_reseller_price_not_main_price
تبدیل شد.

این همان موردی است که در «باقی‌مانده»ی فاز ۱۵ زیر به‌عنوان باز فهرست
شده بود؛ از این پس حل‌شده محسوب می‌شود.

=== اجرا ===
    php artisan test tests/Feature/Architecture/FinalModelSpecTest.php
    php artisan test tests/Feature/Renewal

---

Stage 14 (سند بند ۵۹) — Static Search Audit

جزئیات: docs/STAGE-14-STATIC-SEARCH-AUDIT.md

نتیجه: این مرحله از قبل کامل بود. جست‌وجوی امروز طبق بند ۵۷
(base_price|core_price|sold_price|custom_price|corePrice|soldPrice|
sellingPriceForReseller) روی app/resources/routes/config/tests/
database/factories/database/seeders صفر نتیجه داد. محافظِ دائمی‌اش
(PricingNamingAndReportsTest، از فاز ۱۲) سرِ جایش است و امروز هم رد
شد. هیچ تغییر کدی لازم نبود — فقط این Audit برای رکورد ثبت شد.

=== اجرا ===
    php artisan test tests/Feature/Architecture/PricingNamingAndReportsTest.php
انتظار: سبز، بدون تغییر رفتار.

---

Phase 15 — Rule 12 (مشتریِ چند نماینده) + حذف ستون‌های legacy جدول wallets

جزئیات: docs/PHASE-15-MEMBERSHIP-AND-WALLET-CONTRACT.md

=== الف) Rule 12 ===
عضویت = CustomerAccount؛ هیچ ستون تک‌مقداری روی User مبنای Scope نیست.
- ربات نماینده: کاربرِ نماینده‌ی دیگر دیگر رد نمی‌شود؛ /start عضویت همان فروشگاه را می‌سازد؛ عضویت غیرفعال بی‌صدا فعال نمی‌شود.
- ResellerCustomerService / Reseller::customers / پنل نماینده / پیام همگانی: همه روی CustomerAccount.
- WalletService: User بدون Context = Main (نه حدس از users.reseller_id). balanceIn(User, StoreContext) اضافه شد.
- AccountService::purchase در فروشگاه نماینده عضویت موجود را می‌طلبد (Scope قبل از ساخت CustomerAccount — بند ۶).
- وب‌هوک ربات اصلی عضویت Main می‌سازد.
- User::reseller() حذف شد؛ خودِ ستون users.reseller_id (بلااستفاده) هنوز در جدول است.

=== ب) حذف ستون‌های legacy wallets ===
Migration 2026_09_23_000001: owner_type / owner_id / customer_account_id + ایندکس‌ها و FK حذف می‌شوند.
⚠️ اگر Walletی بدون user_id مانده باشد Migration عمداً متوقف می‌شود (ردّ مالک فقط در همین ستون‌هاست).
قبل از اجرا: بکاپ + بررسی DB::table('wallets')->whereNull('user_id')->count() == 0

=== تست ===
جدید: MembershipGuardTest، تست واقعی Rule 12، وب‌هوک چندنماینده‌ای، عضویت غیرفعال، پیام همگانی مشتریِ مشترک.
مهاجرت‌شده: ۱۰ فایل تست (helper مشترک tests/Concerns/StoreMembers.php).
حذف: دو تست backfill کیف‌پول legacy (ستون‌شان دیگر نیست).
تغییر رفتار آگاهانه: assign به نماینده‌ی دوم اکنون مجاز است (تست قدیمیِ برعکس بازنویسی شد).

=== اجرا ===
    php artisan migrate
    php artisan test
انتظار: همه سبز؛ تصمیم بند ۱۸ قبلاً در مرحله ۱۴ حل شده است.
این پچ بدون PHP نوشته و اجرا نشده؛ Migration را حتماً ابتدا روی staging با کپی دیتابیس اجرا کنید.

=== باقی‌مانده ===
- حذف ستون users.reseller_id همراه با Migration/تست‌های تاریخی backfill.
- users.referrer_id سراسری است (نه per-store).
