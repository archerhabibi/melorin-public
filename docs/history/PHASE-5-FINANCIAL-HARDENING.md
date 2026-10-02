# فاز ۵ — Financial Hardening (Integer Minor Unit + ارز قابل‌تنظیم)

پایه: v3.3.1 (فاز ۴). خروجی: v3.3.2.

## تصمیم
معماری «Minor Unit + ارز قابل‌تنظیم» (پچ دوم) + سخت‌گیری Migration (پچ اول) + یک `Money` واحد و کامل.
پچ دومِ خام اعمال نشد چون فایل‌های جدید (Money، MoneyInput، migration، تست‌ها، سند) را نداشت.

## قاعده‌ی نهایی
| لایه | قاعده |
|---|---|
| Core (Service/Model) | فقط `int` Minor Unit؛ بدون برچسب ارز و بدون `number_format` |
| UI | `Money::format()` / `Money::number()` |
| Input | `Money::parse()` / `parseLoose()` / `parseOrZero()` / `toMinor()` / `MoneyInput` (Filament) |
| Gateway | تبدیل صریح `Money::toRial()` (فقط IRT×۱۰ و IRR×۱؛ غیر از این Exception) |

## جدول عملیاتی
| تغییر | وضعیت | اقدام |
|---|---|---|
| Currency abstraction (`config('melorin.currency')`) | ✅ | نگه داشته شد |
| Money UI centralization | ✅ | نگه داشته شد |
| `Money.php` | ✅ | ادغام پچ ۱+۲ (متدها در README کلاس) |
| `MoneyInput` (Filament) | ✅ | افزوده شد (در پچ ۲ نبود) |
| Migration | 🔧 | سخت‌گیر: توقف روی اعشار، توقف با decimals>0 روی داده، برگشت‌ناپذیر |
| قفل ارز | 🔧 | `system_meta` + `CurrencyLock::verify()` در Boot |
| Zarinpal | 🔧 | `Money::toRial()` |
| Telegram / Website / Filament | ✅ | از پچ ۲ + اصلاح اعتبارسنجی کنترلرها |
| Rounding | ⚠️ | پیش‌فرض ممنوع؛ فقط `MELORIN_MONEY_ALLOW_ROUNDING=true` |
| Multi-Currency هم‌زمان | ⏳ | خارج از Scope |

## Audit (float / تومان)
- `float` مالی در `app/`: صفر (گارد دائمی `MoneyIntegerGuardTest`).
- «تومان» hard-code: فقط در `Money.php` (پیش‌فرض برچسب + پیام خطا). گارد دائمی.
- باقی‌مانده‌ی `round/number_format/decimal`: حجم (traffic)، درصد (`commission_percent`، `commission_rate`)، آمار شمارشی، `Broadcast` درصد پیشرفت؛ و migration تاریخی `2026_09_22_000002` (سنت) که قبل از تبدیل اجرا می‌شود.

## قفل ارز
`code:decimals` در پایان Migration مبالغ ثبت می‌شود. بعد از آن تغییر `MELORIN_CURRENCY_CODE/DECIMALS`
با `CurrencyLockException` رد می‌شود (وب و تست؛ دستورهای اصلاحی Artisan مثل migrate/config:clear معاف‌اند).
`label` و `symbol_position` ظاهری‌اند.

## اجرا
1. Backup. 2. `.env`: ارز را قبل از اولین migrate بگذارید. 3. `php artisan migrate`. 4. `php artisan test`.
⚠️ تست‌ها نوشته شده‌اند ولی در محیط ساخت PHP نبود و اجرا نشده‌اند.
