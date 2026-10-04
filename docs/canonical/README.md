# docs/canonical — اسناد مرجع Melorin

فقط این پوشه **منبع تصمیم** است. هر چیز دیگری در `docs/` یا کد (کامنت، Phase Report) اگر با این اسناد تناقض داشت، این اسناد برنده‌اند.

| فایل | نسخه | نقش |
|---|---|---|
| MASTER-ARCHITECTURE-CONTRACT.md | 2.8 | تنها مرجع معماری (CANONICAL) |
| WEBSITE-ARCHITECTURE-CONTRACT.md | 1.9 (Parent: Master 2.8) | Contract کانال Website |
| VERIFICATION-MATRIX.md | Contract 2.8 / 1.9 | وضعیت Contract→Code→Test→Production + شکاف‌ها |
| GOOGLE-SIGNIN-CONTRACT.md | 1.2 (Parent: Master 2.8 / Website 1.9) | Google Sign-In (B2.1؛ G12–G21) |
| EMAIL-AUTH-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Google 1.2) | Email Authentication (B2.2؛ E1–E6) |
| ACCOUNT-LINKING-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Google 1.2 / Email 1.0) | Account Linking: Google/Telegram/اولین رمز (B2.4؛ L1–L11) |
| SESSION-SECURITY-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Email 1.0 / Linking 1.0) | Session Security: سیاست نشست، مدیریت دستگاه‌ها، تغییر رمز (B2.5؛ S1–S11) |
| CUSTOMER-DASHBOARD-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9) | Customer Dashboard: سرویس‌های فعال، کیف‌پول، اعلان‌های مشتق‌شده (B3.1؛ D1–D6) |
| CUSTOMER-SERVICES-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Dashboard 1.0) | Service Management: مصرف زنده، پیش‌فاکتور و تمدید، بدون ارتقا (B3.2؛ S1–S6) |
| CUSTOMER-WALLET-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Dashboard 1.0 / Services 1.0) | Wallet Center: خلاصه، گردش حساب فیلتر‌پذیر، وضعیت شارژها، صفحه‌ی شارژ (B3.3؛ W1–W7) |
| CUSTOMER-TICKETS-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Dashboard 1.0 / Wallet 1.0) | Ticket Center: فهرست، ثبت، گفتگو، پاسخ، بستن؛ مالکیت user+reseller (B3.4؛ T1–T7) |
| CUSTOMER-PROFILE-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Linking 1.0 / Sessions 1.0 / Tickets 1.0) | Profile Center: نمای کلی، ویرایش نام/موبایل، قاعده‌ی نام تلگرام، یکی‌بودن Core بین Website و ربات (B3.5؛ P1–P9) |
| CUSTOMER-CATALOG-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Profile 1.0) | Product Catalog: سرویس واحد Core برای سایت و دو ربات، جست‌وجو/فیلتر/مرتب‌سازی، ظرفیت تکمیل، بدون N+1 (B4.1؛ C1–C9) |
| CUSTOMER-GUEST-CHECKOUT-UX-CONTRACT.md | 1.0 (Parent: Master 2.8 / Website 1.9 / Catalog 1.0) | Guest Checkout UX: مراحل، خلاصه‌ی خرید، ویرایش اطلاعات (Prefill)، محافظ ظرفیت تکمیل، فرم دسترس‌پذیر؛ بدون تغییر قرارداد داده (B4.3؛ U1–U7) |
| DATA-RETENTION.md | — | Retention (Guest ۶۰ روز؛ بقیه تأییدشده) |

## سایر پوشه‌ها

| پوشه | محتوا |
|---|---|
| `../operations/` | راهنمای Deploy/Staging (`DEPLOY-CHECKLIST.md`، `STAGING-RUNBOOK.md`) |
| `../history/` | تاریخچه: گزارش فازها (`PHASE-*`)، تصمیم‌ها، `CHANGELOG.md`، و اسناد **DEPRECATED** (Master v2.3، Website v1.2، `ARCHITECTURE-3.1.1-SUPERSEDED.md`). منبع تصمیم نیست. |

Master v2.3 و Website v1.2 و `ARCHITECTURE.md` نسخه‌ی ۳.۱.۱ قدیمی **DEPRECATED** هستند و در `../history/` نگهداری می‌شوند.
