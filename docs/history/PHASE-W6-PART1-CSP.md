# فاز W6 (بخش اول) — CSP Header (v3.2.6)

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W6 بند ۲ + بخش ۹.۶ + بخش ۱۰ (نقشه‌ی
مالکیت نفر ۴: امنیت و سخت‌سازی).

**از این پچ به بعد نقش «نفر ۴» را ایفا می‌کنم** — طبق نقشه‌ی مالکیت،
فقط `Http/Middleware/` (امنیتی)، تنظیمات Disk خصوصی رسید، و Config
های Rate Limit/CSP را دست می‌زنم؛ به روایت Roadmap («این فاز
Security-critical است، هر آیتم Patch جدای خودش») هر بند W6 یک پچ جدا
می‌شود: ۳.۲.۶ (CSP)، ۳.۲.۷ (Rate Limiting)، ۳.۲.۸ (Receipt Storage)،
۳.۲.۹ (Audit)، ۳.۲.۱۰ (Review امنیتی Telegram-linking).

## چه چیزی ساخته شد

- `SetContentSecurityPolicyHeader` middleware، با alias `website.csp`
  (ثبت‌شده در `bootstrap/app.php`، نه Kernel چون پروژه روی سبک
  Laravel 11 است).
- فقط به دو گروه Route موجود در `routes/website.php` اضافه شد (هم
  Main هم Reseller) — **نه سراسری روی گروه `web`** — چون پنل ادمین/
  نماینده (Filament + Livewire) به Inline Script/Style های خودش
  متکی است؛ یک CSP سخت‌گیرانه‌ی سراسری آن را می‌شکند و اصلاح‌کردنش
  خارج از Scope این پچ (و اصلاً خارج از Scope کل این Roadmap، چون
  پنل ادمین بخشی از «Website» نیست).

## تصمیمات و سازش‌های آگاهانه

- `style-src 'self' 'unsafe-inline'`: تمام Viewهای Website (پچ‌های
  ۳.۲.۱ تا ۳.۲.۵) از `style="background: var(--brand)"` inline
  استفاده می‌کنند چون رنگ Brand نماینده در Runtime از StoreContext
  می‌آید، نه Build-time. حذف این سازش یعنی بازنویسی همه‌ی Viewهای
  Website به یک مکانیزم Nonce یا CSS Custom Property تزریق‌شده از
  سرور بدون `style=`. **این کار آینده است، نه این پچ** — چون تغییر
  چندین View که مالکیت‌شان با نفرات دیگر است (۱، ۲، ۳) بدون هماهنگی با
  آن‌ها خلاف اصل «هرکس فقط پوشه‌ی خودش» می‌شود. Inline Style بسیار
  کم‌خطرتر از Inline Script است (نمی‌تواند کد دلخواه اجرا کند، فقط
  ظاهر را تغییر می‌دهد)، پس این سازش آگاهانه و مستند است.
- `script-src 'self' https://telegram.org`: فقط برای ویجت رسمی
  Telegram Login (پچ ۳.۲.۵). بدون `unsafe-inline` روی اسکریپت، چون
  هیچ Inline Script ای در Viewهای موجود نیست.
- دامنه‌ی Zarinpal عمداً در CSP نیست: `ChargeController` (پچ ۳.۲.۲) با
  `redirect()->away()` کار می‌کند — یک Navigation کامل مرورگر، نه
  iframe/fetch — و CSP روی Navigation سطح‌بالا اعمال نمی‌شود.

## تست

`tests/Feature/Website/ContentSecurityPolicyTest.php` — هدر روی
صفحه‌ی اصلی Main و Reseller.

## خارج از Scope این پچ

- حذف `style-src unsafe-inline` با بازنویسی Viewها به Nonce — نیاز به
  هماهنگی با نفرات ۱-۳، کار آینده.
- CSP روی پنل ادمین/نماینده — خارج از تعریف «Website» در این Roadmap.

## محدودیت این پچ

بدون PHP اجرا‌پذیر نوشته شده — `php artisan test --filter=ContentSecurityPolicyTest`
را روی محیط واقعی اجرا و نتیجه را تایید کنید؛ همچنین چک دستی کنید که
هیچ صفحه‌ی Website ای (مخصوصاً هرچیزی که بعداً اسکریپت این‌لاین اضافه
می‌کند) با این CSP نشکند.
