# docs/canonical — اسناد مرجع Melorin

فقط این پوشه **منبع تصمیم** است. هر چیز دیگری در `docs/` یا کد (کامنت، Phase Report) اگر با این اسناد تناقض داشت، این اسناد برنده‌اند.

| فایل | نسخه | نقش |
|---|---|---|
| MASTER-ARCHITECTURE-CONTRACT.md | 2.8 | تنها مرجع معماری (CANONICAL) |
| WEBSITE-ARCHITECTURE-CONTRACT.md | 1.8 (Parent: Master 2.8) | Contract کانال Website |
| VERIFICATION-MATRIX.md | Contract 2.8 / 1.8 | وضعیت Contract→Code→Test→Production + شکاف‌ها |
| DATA-RETENTION.md | — | Retention (Guest ۶۰ روز؛ بقیه تأییدشده) |

## سایر پوشه‌ها

| پوشه | محتوا |
|---|---|
| `../operations/` | راهنمای Deploy/Staging (`DEPLOY-CHECKLIST.md`، `STAGING-RUNBOOK.md`) |
| `../history/` | تاریخچه: گزارش فازها (`PHASE-*`)، تصمیم‌ها، `CHANGELOG.md`، و اسناد **DEPRECATED** (Master v2.3، Website v1.2، `ARCHITECTURE-3.1.1-SUPERSEDED.md`). منبع تصمیم نیست. |

Master v2.3 و Website v1.2 و `ARCHITECTURE.md` نسخه‌ی ۳.۱.۱ قدیمی **DEPRECATED** هستند و در `../history/` نگهداری می‌شوند.
