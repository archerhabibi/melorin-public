# Verification Matrix — فاز W7 (نفر ۵)

مرجع: `ROADMAP-WEBSITE-v1.md` فاز W7 + بند ۱۰۴ زیرسند + بند ۱۲۸ سند
مادر («Website زمانی Done است که همه‌ی ردیف‌ها به PRODUCTION VERIFIED
برسند، نه فقط IMPLEMENTED»).

**قانون این جدول** (دقیقاً همان درسِ باگ پچ ۳.۱.۹ که Roadmap صریح
یادآوری کرده): TESTED یعنی تستِ واقعی نوشته شده **و** با
`php artisan test` واقعاً اجرا و سبز شده — نه فقط نوشته شده. چون این
محیط PHP اجرا‌پذیر ندارد (همه‌ی پچ‌های ۳.۲.۱ تا ۳.۲.۱۲ در چنین محیطی
نوشته شدند)، ستون TESTED در این نسخه از جدول یعنی **«تست نوشته شده،
منتظر تایید اجرای واقعی شماست»**، مگر جایی که صریح نوشته شده شما قبلاً
نتیجه‌ی سبز را تایید کرده‌اید.

| ردیف (بند ۱۰۴) | SPECIFIED | IMPLEMENTED | TESTED | PRODUCTION VERIFIED | تست‌ها |
|---|---|---|---|---|---|
| Guest Checkout | ✅ | ✅ (۳.۲.۳–۳.۲.۵؛ نماینده: ۳.۲.۱۵) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `GuestCheckoutTokenTest`, `GuestE2ETest`, `ResellerGuestCheckoutTest` |
| Login | ✅ | ✅ (W1) | **تایید شما (پیام قبلی: «همه تست‌ها سبز»)** برای نسخه‌ی تا ۳.۲.۳؛ E2E جدید هنوز نیاز به اجرا دارد | ❌ | `MainWebsiteE2ETest`, `GuestPostPurchaseE2ETest` |
| Register | ✅ | ✅ (W1) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `MainWebsiteE2ETest`, `ReferralRegistrationTest` |
| CustomerAccount | ✅ | ✅ (Core + W1) | غیرمستقیم از طریق تست‌های بالا | ❌ | همان |
| Wallet | ✅ | ✅ (۳.۲.۱، ۳.۲.۲، ۳.۲.۱۱) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `WalletChargeFlowTest`, `AccountPanelTest` |
| Main Purchase | ✅ | ✅ | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `CheckoutFlowTest`, `MainWebsiteE2ETest` |
| Reseller Purchase | ✅ | ✅ (Context-agnostic + تست صریح نفر ۳ در ۳.۲.۱۴) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `ResellerCommerceFlowTest` (Double-Debit، Wallet/Order Scope) |
| Payment (Zarinpal) | ✅ | ✅ (۳.۲.۲) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website)؛ **توصیه**: یک تست دستی روی Zarinpal Sandbox واقعی قبل از Production (فقط `Http::fake` کافی نیست برای این مورد) | ❌ | `WalletChargeFlowTest`, `PaymentServiceTest` (از قبل در Core) |
| Card-to-Card | ✅ | ✅ (۳.۲.۲، سخت‌سازی در ۳.۲.۸) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `WalletChargeFlowTest`, `ReceiptSecurityTest` |
| Orders | ✅ | ✅ (۳.۲.۱ تک‌سفارش، ۳.۲.۱۱ فهرست کامل) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `CheckoutFlowTest`, `AccountPanelTest`, هر دو E2E |
| Provisioning (نمایش وضعیت) | ✅ | ✅ (۳.۲.۱، از Core) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `MainWebsiteE2ETest` |
| Renewal | ✅ (Roadmap W4 بند ۴) | ✅ (۳.۲.۱۲، نفر ۲ — همان منطق `AccountsHandler::renew()` ربات) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `AccountPanelPart2Test` |
| Referral / Commission | ✅ (W4 بند ۵) | ✅ (۳.۲.۱۲ نفر ۲ + ۳.۲.۱۳ نفر ۵: خواندن `?ref=` در ثبت‌نام، نکته‌ی بازِ نفر ۲) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `AccountPanelPart2Test`, `ReferralRegistrationTest` |
| Refund | ✅ (W4 بند ۶) | ❌ عمداً معوق (هیچ UI ای وجود ندارد؛ بند مربوطه در Audit هم N/A علامت خورد) | ❌ | ❌ | — |
| Reseller Management | ✅ (فاز W5) | ✅ بخش اول (۳.۲.۱۴، نفر ۳: Branding، منوی نماینده، مدیریت مشتری/محصول) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website) | ❌ | `ResellerManagementTest`, `ResellerBrandingTest` |
| Security (W6) | ✅ | ✅ (۳.۲.۶ تا ۳.۲.۱۰، کامل) | ✅ سبز (اجرای واقعی ۲۰۲۶-۰۹-۳۰: ۹۹ تست Website)؛ **یادآوری**: پچ ۳.۲.۱۰ یک آسیب‌پذیری واقعی (CSRF) پیدا و رفع کرد و صراحتاً یک Review واقعاً مستقل را توصیه کرد | ❌ | `ContentSecurityPolicyTest`, `RateLimitingTest`, `ReceiptSecurityTest`, `AuditLoggingTest`, `TelegramLinkingTest` |

## E2Eهای صریح Roadmap (بند ۶۱–۶۴ زیرسند)

| E2E | وضعیت |
|---|---|
| Main Website E2E | این پچ: `tests/Feature/Website/MainWebsiteE2ETest.php` |
| Reseller Website E2E | `ResellerCommerceFlowTest` (نفر ۳، ۳.۲.۱۴) + Guest نماینده: `ResellerGuestCheckoutTest` (۳.۲.۱۵) — نیاز به اجرای واقعی |
| Guest E2E | این پچ: `tests/Feature/Website/GuestE2ETest.php` |
| Guest Post-Purchase E2E | این پچ: `tests/Feature/Website/GuestPostPurchaseE2ETest.php` |

## اسکلت تست مشترک (بخش ۱۰ Roadmap)

`tests/Concerns/InteractsWithWebsiteFixtures.php` — این پچ اضافه شد.
**دیر منتشر شد**: طبق Roadmap باید از روز اول کنار نفرات ۱ تا ۴ می‌آمد؛
چون همه‌ی پچ‌های قبلی (۳.۲.۱ تا ۳.۲.۱۱) پیش از تفکیک رسمی نقش نفر ۵
نوشته شده بودند، هرکدام نسخه‌ی خودشان از `makeProduct()`/Http::fake را
تکرار کرده‌اند. این تکرار به‌عنوان بدهی فنی مستند شد، نه پاک‌سازی
اجباری در همین پچ (بازنویسی تست‌های از قبل سبز، بدون سود رفتاری، فقط
ریسک اضافه می‌کند).

## خلاصه‌ی وضعیت کلی

هیچ ردیفی به PRODUCTION VERIFIED نرسیده — طبیعی است، چون هنوز حتی
Staging واقعی وجود ندارد (فاز W8، مستند جدا:
`docs/PHASE-W8-DEPLOY-CHECKLIST.md`). قبل از هر ادعای Done بودن،
حداقل باید:
1. `composer install` + `php artisan test` کامل (نه فقط فایل‌های
   جدید) روی یک محیط واقعی اجرا شود.
2. نفر ۳ فروشگاه نماینده را تحویل دهد تا ردیف‌های Reseller قابل تکمیل
   شوند (نفر ۱، ۲ و ۴ طبق نقشه‌ی مالکیت‌شان کامل شده‌اند).
3. یک Review امنیتی واقعاً مستقل روی Telegram-linking (طبق توصیه‌ی
   صریح پچ ۳.۲.۱۰).

## به‌روزرسانی (پچ ۳.۲.۱۷)

یافته‌ی امنیتیِ گزارش‌شده در انتهای این بازبینی (`EnsureCustomerAccountResolved`
با هر GET یک CustomerAccount می‌ساخت) توسط صاحب پروژه با یک قاعده‌ی
صریح رفع شد: «تا خرید انجام نشود نباید CustomerAccount جدید بسازد».
جزئیات و تست‌ها در `docs/PHASE-W5-PART3-LAZY-CUSTOMER-ACCOUNT.md`. این
موضوع override ای است روی تحلیل قبلی نفر ۳ در
`docs/PHASE-W5-PART2-COMPLETION-AND-SECURITY-NOTE.md`.

## به‌روزرسانی (نسخه‌ی 3.3.0)

`php artisan test --filter=Website` روی محیط واقعی: **۹۹ تست، ۳۴۶ assertion، همه سبز**
(همه‌ی ردیف‌های TESTED بالا). در این اجرا چند باگ واقعی پیدا و رفع شد؛ فهرست در `VERSION` و
`docs/RELEASE-3.3.0-AUDIT.md`. هنوز: `php artisan test` **کامل** (کل پروژه)، Staging،
Zarinpal Sandbox و Review امنیتی مستقل انجام نشده؛ پس هیچ ردیفی PRODUCTION VERIFIED نیست.
