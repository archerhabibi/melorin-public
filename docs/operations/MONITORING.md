# Monitoring & Alerting

> **وضعیت:** سیگنال‌ها در کد وجود دارند (فاز ۹)؛ **اتصال به ابزار Alert (Uptime Kuma / healthchecks.io / Prometheus / Grafana / ELK…) انتخاب مالک و هنوز انجام نشده است.** این سند می‌گوید چه چیزی را چگونه پایش کنید.

## سیگنال‌های آماده
| سیگنال | منبع | معنا |
|---|---|---|
| Liveness | `GET /up` | فرآیند PHP پاسخ می‌دهد |
| **Readiness** | `GET /health/ready` (JSON، ۲۰۰/۵۰۳، بدون Session، `no-store`) | DB، Cache، Storage، Migration عقب‌مانده، قفل ارز؛ `scheduler`/`queue_backlog`/`failed_jobs` فقط `warn` (وضعیت `degraded`، بدون ۵۰۳) |
| پایش داده (هر ۱۵ دقیقه) | Scheduler → `melorin:preflight --group=data --log` | `Log::error('melorin_preflight_fail')` / `Log::warning('melorin_preflight_warn')` با فیلد `name` |
| Heartbeat Scheduler | `Cache` کلید `melorin:scheduler:heartbeat` | هر دقیقه؛ `scheduler` در Readiness |
| یتیم پنل | `Log::critical('orphan_panel_account')` | ثبت DB بعد از ساخت اکانت شکست خورد و پاک‌سازی هم ناموفق بود |

## قواعد Alert پیشنهادی
| شدت | شرط | اقدام (Runbook) |
|---|---|---|
| 🔴 Page | `/health/ready` ≠ ۲۰۰ برای ۲ بررسی پیاپی (۳۰s) | INCIDENT §۶/§۹ |
| 🔴 Page | لاگ `melorin_preflight_fail` با `name` ∈ {`ledger_matches_balance`, `last_balance_after_matches`, `confirmed_payments_credited`} | INCIDENT §۸ / §۲ |
| 🔴 Page | لاگ `orphan_panel_account` | INCIDENT §۴ |
| 🟠 Ticket | `melorin_preflight_warn` با `name` ∈ {`orders_not_stuck`, `retries_not_overdue`, `operations_not_stuck`} | INCIDENT §۵/§۳ |
| 🟠 Ticket | `scheduler` یا `queue_backlog` = warn بیش از ۵ دقیقه | INCIDENT §۹ |
| 🟠 Ticket | `provision_failed_orders` > آستانه (مثلاً ۵) یا رشد ناگهانی | وضعیت پنل‌ها |
| 🟡 Review | `resellers_have_webhook_secret` / `reseller_webhooks_registered` | Reconnect webhook |

مثال Uptime Kuma: Monitor نوع HTTP روی `https://HOST/health/ready`، Keyword `"status":"ok"` (یا `degraded` را هم پذیرفتن)، فاصله ۳۰s.

## متریک‌های کسب‌وکار (SQL فقط‌خواندنی؛ برای Grafana/Cron)
```sql
-- موفقیت/شکست خرید در ساعت گذشته
SELECT status, COUNT(*) FROM orders WHERE created_at >= NOW() - INTERVAL 1 HOUR GROUP BY status;
-- پرداخت‌های Pending قدیمی‌تر از ۲۴ ساعت
SELECT COUNT(*) FROM payments WHERE status='pending' AND created_at < NOW() - INTERVAL 24 HOUR;
-- شارژ/برداشت امروز
SELECT type, COUNT(*), SUM(amount) FROM wallet_transactions WHERE created_at >= CURDATE() GROUP BY type;
-- Attemptهای Provisioning ناموفق ۲۴ ساعت گذشته
SELECT COUNT(*) FROM provisioning_attempts WHERE status='failed' AND created_at >= NOW() - INTERVAL 1 DAY;
-- Failed jobs
SELECT COUNT(*) FROM failed_jobs;
```

## هنوز نیست (بدهی شناخته‌شده)
- Correlation ID واحد Request→Operation→Order→Attempt (امروز فقط `operations.idempotency_key` و `provisioning_attempts.operation_id`).
- Alert خودکار روی پایش داده بدون ابزار خارجی (فقط لاگ).
- Metric Export (Prometheus). پیشنهاد: Exporter سبک روی همین SQLها.
