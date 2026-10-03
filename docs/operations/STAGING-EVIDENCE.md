# Staging Evidence — Release 3.3.8

قاعده: هر خانه فقط با **اجرای واقعی** پر می‌شود (✅/❌ + تاریخ + مدرک: لاگ، اسکرین‌شات، خروجی دستور). تا آن زمان ☐. این فایل جایگزین حدس و گزارش حافظه است.

| شناسه | سناریو (Runbook §) | نتیجه | تاریخ | مدرک / یادداشت |
|---|---|:-:|---|---|
| E0 | `preflight --group=config --strict` (§۱) | ☐ | | |
| E1 | Backup + Migration روی کپی DB (§۲) | ☐ | | |
| E2 | `backup-restore-drill.sh` — RTO ثبت‌شده (§۲) | ☐ | | RTO = ___ s |
| E3 | `preflight --strict` + `smoke.sh` (§۳) | ☐ | | |
| P1 | Zarinpal Sandbox — شارژ موفق | ☐ | | |
| P2 | Callback تکراری | ☐ | | |
| P3 | پرداخت لغو/ناموفق | ☐ | | |
| P4 | Card-to-Card + رسید | ☐ | | |
| G1 | Guest E2E با ایمیل واقعی | ☐ | | |
| R1 | خرید فروشگاه نماینده (Debit دوگانه) | ☐ | | |
| T1 | Telegram Link + IP واقعی پشت Tunnel | ☐ | | |
| T2 | وب‌هوک اصلی/نماینده | ☐ | | |
| A1 | ورود/خروج پنل‌ها و `sign-out` | ☐ | | |
| V1 | Provisioning موفق (پنل واقعی) | ☐ | | |
| V2 | Timeout + Retry بدون Debit دوباره | ☐ | | |
| V3 | پاسخ گمشده (G-9-2) | ☐ | | رفتار مشاهده‌شده: |
| V4 | درخواست تکراری همزمان | ☐ | | |
| V5 | Crash وسط Provisioning (G-9-1) | ☐ | | رفتار مشاهده‌شده: |
| V6 | Retry بعد از Restart | ☐ | | |
| V7 | رد پنل + سیاست Refund | ☐ | | |
| C1 | Concurrency MariaDB (suite) | ☐ | | |
| C2 | Concurrency چندپردازه (`sale_limit=1`) | ☐ | | |
| B1 | Rollback — شکست HTTP عمدی | ☐ | | |
| B2 | Rollback — شکست Migration عمدی | ☐ | | |
| B3 | Restore کامل روی Staging | ☐ | | |
| S1 | Independent Security Review | ☐ | | شخص/ابزار: |
