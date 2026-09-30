# فاز W3 (بخش سوم) — Telegram-linking واقعی (v3.2.5)

مرجع: `ROADMAP-WEBSITE-v1.md` بند «Guest-to-Telegram linking» (خط ۳۳۱)
+ بند ۵ فاز W3 + بند ۵ فاز W6 («Review امنیتی، نه ساخت») + بخش ۱۰
(نقشه‌ی مالکیت نفر ۱).

پچ متناظر: `melorin-website-v3.2.5.patch`. ادامه‌ی بی‌واسطه‌ی ۳.۲.۴.

## نکته‌ی امنیتی که این پچ صریحاً رعایت کرد

Roadmap با تاکید نوشته: **«نسخه‌ی VPNMarket (HMAC جعلی +
`user_id` بدون اعتبارسنجی) به هیچ عنوان استفاده نمی‌شود.»**
`TelegramLoginVerifier` پیاده‌سازی رسمی الگوریتم
[Telegram Login Widget](https://core.telegram.org/widgets/login#checking-authorization)
است:

1. `data_check_string` از همه‌ی فیلدهای query (به‌جز `hash`)، مرتب‌شده
   بر اساس کلید.
2. `secret_key = SHA256(bot_token واقعی)` — نه یک مقدار ثابت یا جعلی.
3. `HMAC-SHA256(data_check_string, secret_key)` با `hash_equals()`
   (مقایسه‌ی ثابت‌زمانی، امن در برابر Timing Attack) با hash دریافتی
   مقایسه می‌شود.
4. `auth_date` هم چک می‌شود (حداکثر ۲۴ ساعت) تا یک callback URL قدیمی
   قابل Replay نباشد.

## تصمیم Scope: «Linking»، نه «Login»

خودِ متن Roadmap این قابلیت را این‌طور توصیف کرده: **«یک قابلیت
اختیاریِ بعدی برای همین CustomerAccount (نه پیش‌نیاز خرید)»**. یعنی
هدف اصلی، وصل‌کردن تلگرام به یک حساب از‌قبل‌موجود است، نه یک مسیر
ثبت‌نام/ورود جدید برای بازدیدکننده‌ی ناشناس. به همین دلیل
`TelegramLinkController::callback` عمداً **پشت `auth`** است:

- اگر کاربر لاگین است (مثلاً همان User ناقصِ برآمده از
  `GuestPurchaseController`، پچ ۳.۲.۴) → تلگرامش را به همین حساب وصل
  می‌کند.
- «ورود با تلگرام» برای کسی که اصلاً لاگین نیست، در این پچ **نیست** —
  یک تصمیم امنیتی جدا و بزرگ‌تر است (باید تصمیم گرفت آیا صرفِ تایید
  تلگرام برای ساختن/ورود به یک حساب کافی است یا نه) که Roadmap هم آن
  را باز نکرده؛ عمداً به آینده موکول شد.

## محافظت در برابر Merge بدون اثبات (بند ۸۷ سند مادر)

حتی با امضای HMAC معتبر (یعنی واقعاً همان اکانت تلگرام است):

- اگر آن `telegram_id` از قبل به یک User **دیگر** وصل است → رد می‌شود
  با پیام «قبلاً به کاربر دیگری متصل است»، هیچ ادغامی رخ نمی‌دهد. چرا
  حتی با اثبات معتبر هم رد می‌شود: معلوم نیست کدام Session فعلی واقعاً
  «همان آدم» پشت هر دو حساب است — این خودش یک تصمیم ادغام هویت است که
  باید آگاهانه (فاز B که در کد Core هم اشاره شده) انجام شود، نه در یک
  Redirect ساده.
- Race Condition (دو تب هم‌زمان): محافظت با همان `unique` دیتابیس روی
  `users.telegram_id` (از قبل موجود بود) + گرفتن `QueryException`.

## چه چیزی ساخته شد

- `TelegramLoginVerifier` (`app/Channels/Website/Support/`) — منطق
  HMAC، خالص و بدون وابستگی به HTTP/Session، به‌راحتی Unit-testable.
- `TelegramLinkController` (`Controllers/Identity/`).
- Route: `GET /identity/telegram/callback` (طبق مستندات ویجت، این یک
  GET با query params امضاشده است، نه POST) — داخل همان گروه
  `auth+store.customer`، با `throttle:20,1`.
- ویجت رسمی تلگرام (`telegram-widget.js`) در `complete-profile.blade.php`
  — نه یک دکمه‌ی دست‌ساز؛ اسکریپت مستقیماً از دامنه‌ی خودِ Telegram
  لود می‌شود.
- تست: `tests/Feature/Website/TelegramLinkingTest.php` — لینک موفق،
  hash دستکاری‌شده، `auth_date` منقضی، تلگرام متعلق به کاربر دیگر، و
  رد دسترسی مهمان.

## خارج از Scope این پچ (عمدی)

- **فروشگاه نماینده**: ویجت تلگرام نیاز به `data-telegram-login="{bot_username}"`
  دارد؛ جدول `resellers` فقط `bot_token` دارد، نه `bot_username` (و
  ستون‌های امنیتی/schema، طبق بخش ۱۰، مالکیت نفر ۴ است، نه من). فعلاً
  `complete-profile.blade.php` این بخش را فقط برای Main Context نشان
  می‌دهد (`$telegramBotUsername = null` برای نماینده).
- **CSP برای دامنه‌ی `telegram.org`**: خودِ Roadmap این را زیر تنظیمات
  W6 لیست کرده که مالکیتش با نفر ۴ است؛ من فقط همین‌جا یادآوری
  می‌کنم که وقتی نفر ۴ Header CSP را می‌سازد، باید `telegram.org` را
  برای این اسکریپت مجاز کند، وگرنه ویجت لود نمی‌شود.
- **«ورود با تلگرام» برای کاربر ناشناس** — بالا توضیح داده شد.
- **Review امنیتی مستقل** — طبق بخش ۱۰ («نفر ۴ کار Telegram-linking
  نفر ۱ را Review می‌کند») وظیفه‌ی من نیست که خودم را Review کنم؛ این
  پچ آماده‌ی همان Review است.

## تست

`php artisan test --filter=TelegramLinkingTest` — چهار حالت رد و یک
حالت قبول، بدون هیچ Mock از Telegram (چون کل منطق سمت سرور، ریاضی
محض HMAC است، نیازی به `Http::fake` هم نبود).

## محدودیت این پچ

بدون PHP/Composer اجرا‌پذیر نوشته شده — نتیجه‌ی واقعی
`php artisan test --filter=TelegramLinkingTest` را بفرستید.

## قدم بعدی نفر ۱

طبق بخش ۱۰، W3 (کامل) به پایان رسید. قدم بعدی من رسماً چیزی نیست مگر
این‌که نفر ۴ در Review چیزی بخواهد؛ اگر خواستید، می‌توانم:
(الف) `bot_username` را برای نمایندگان اضافه کنم (نیاز به هماهنگی با
نفر ۴/۳)، یا (ب) به سراغ فاز دیگری بروم.
