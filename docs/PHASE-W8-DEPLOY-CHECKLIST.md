# فاز W8 — چک‌لیست Staging → Production

مرجع: ROADMAP-WEBSITE-v1.md فاز W8 + بند 73 زیرسند + بند 131 سند مادر.

ترتیب Deploy طبق بند 98/99 زیرسند:

```text
Core (تکمیل‌شده)
 -> Financial Flow (تکمیل‌شده)
 -> Telegram Integrations (تکمیل‌شده)
 -> Main Website  <- این‌جا
 -> Reseller Websites  <- منتظر نفر 3
 -> Monitoring
```

## چک‌لیست بند 73 (باید همه در Staging سبز شوند، قبل از Production)

| مورد | وضعیت | یادداشت |
|---|---|---|
| Migration | در انتظار اجرا | همه‌ی Migrationهای پچ‌های 3.2.1 تا 3.2.13 (guest_checkouts، widen_audit_logs_actor_type) باید با php artisan migrate روی Staging اجرا و بررسی شوند |
| Backup | در انتظار | قبل از اولین migrate روی Staging/Production، از دیتابیس فعلی Backup گرفته شود - این پچ‌ها به جدول‌های Core (users, audit_logs) هم دست زده‌اند |
| Payment | در انتظار | Wallet تست‌شده (کد)؛ Zarinpal فقط با Http::fake تست شده - باید یک تراکنش واقعی روی Zarinpal Sandbox قبل از Production انجام شود |
| Purchase | در انتظار | CheckoutFlowTest، MainWebsiteE2ETest - نیاز به اجرای واقعی |
| Provisioning | تایید شده از قبل | از Core، در فازهای A1-A3 قبلا Verified شده؛ Website فقط وضعیتش را نمایش می‌دهد |
| Retry | N/A برای Website | طبق تصمیم صریح پچ 3.2.12، Retry کاملا Admin-only می‌ماند |
| Refund | N/A برای Website | همان‌طور؛ هیچ UI ای برای مشتری ساخته نشده |
| Guest Checkout | در انتظار | GuestCheckoutTokenTest، GuestPurchaseFlowTest، GuestE2ETest، GuestPostPurchaseE2ETest - نیاز به اجرای واقعی |
| Security | در انتظار | پنج پچ W6 (3.2.6 تا 3.2.10) نوشته شده؛ پچ 3.2.10 صریحا یک Review واقعا مستقل را قبل از Production توصیه کرده - هنوز انجام نشده |
| Audit | در انتظار | AuditLoggingTest - نیاز به اجرای واقعی + بررسی این‌که Migration ستون actor_type روی داده‌ی واقعی Production مشکلی ایجاد نمی‌کند |
| Rollback | در انتظار | هر Migration این مجموعه پچ یک متد down() واقعی دارد؛ Rollback کامل هنوز روی Staging تمرین نشده |

## پیش‌نیازهای مسدودکننده‌ی Production (خلاصه)

1. اجرای واقعی کل تست‌ها: composer install && php artisan test - هیچ‌کدام از پچ‌های 3.2.1 تا 3.2.13 در محیطی با PHP واقعی نوشته نشدند.
2. Review امنیتی مستقل Telegram-linking - طبق توصیه‌ی صریح پچ 3.2.10.
3. تست دستی Zarinpal روی Sandbox واقعی - نه فقط Http::fake.
4. فروشگاه نماینده (نفر 3) - قبل از فعال‌کردن مسیر /store/{slug} روی Production، چون فعلا هیچ Branding/UI اختصاصی‌ای برایش وجود ندارد.
5. .env جدا برای Staging/Production - مخصوصا Secret های Zarinpal و Bot Token تلگرام هرگز نباید بین دو محیط مشترک باشند.

## این Roadmap چه زمانی واقعا Done است

طبق بند 128 سند مادر: وقتی همه‌ی ردیف‌های docs/VERIFICATION-MATRIX.md
به PRODUCTION VERIFIED برسند. تا امروز (پچ 3.2.13)، هیچ ردیفی به آن‌جا
نرسیده - چون هنوز حتی یک بار روی محیط Staging واقعی اجرا نشده‌اند.
