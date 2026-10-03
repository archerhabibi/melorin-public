# Incident Response — Runbook

> رویه‌ها با رفتار فعلی کد نوشته شده‌اند (State Machine پرداخت، Operation/Idempotency، Retry Provisioning). **هیچ‌کدام هنوز در Staging تمرین نشده‌اند** (`STAGING-EVIDENCE.md`).

## اصول
1. **هرگز `UPDATE wallets SET balance …` دستی نزنید.** موجودی فقط با گردش (Ledger) عوض می‌شود: از پنل ادمین «تنظیم دستی موجودی» (`admin_adjust`) با توضیحی که شناسه‌ی Payment/Order را دارد. دست‌کاری مستقیم، چک `ledger_matches_balance` را قرمز می‌کند.
2. اول **Contain** (جلوی آسیب بیشتر)، بعد Diagnose، بعد Fix، بعد Verify با `php artisan melorin:preflight`.
3. هر Incident را با زمان، علت، اقدام و شناسه‌های درگیر ثبت کنید (Audit Log + یادداشت).
4. Backup قبل از هر اصلاح داده‌ی دستی.

## ابزار تشخیص
```bash
php artisan melorin:preflight --group=data      # Ledger، پرداخت بی‌اعتبار، سفارش گیرکرده، Retry عقب‌مانده، نماینده بدون Secret
php artisan melorin:preflight --group=runtime   # DB، Cache، Migration، Scheduler، Queue
curl -s https://HOST/health/ready
grep -E 'melorin_preflight_(fail|warn)|orphan_panel_account' storage/logs/laravel.log
```

## ۱) Wallet اشتباه Debit شد
- **Contain:** اگر الگو سیستماتیک است (باگ) → `php artisan down`.
- **Diagnose:** تراکنش‌های Wallet آن کاربر (`wallet_transactions` با `operation_id`، `reference_type/id`)؛ هر خرید یک `Operation` دارد. دوباره‌کسری با همان Idempotency Key نباید رخ دهد؛ اگر رخ داده، کلیدها را مقایسه کنید.
- **Fix:** Refund از روی سفارش (قیمت Snapshot) اگر سفارش `provision_failed` است؛ وگرنه `admin_adjust` مثبت با توضیح. برای سفارش نماینده **هر دو** طرف (مشتری + Main Wallet نماینده) را بررسی کنید.
- **Verify:** `preflight --group=data` سبز.

## ۲) Payment تأیید شد ولی Wallet شارژ نشد
- **Detect:** `confirmed_payments_credited` = fail (لیست شناسه‌ها).
- **نکته‌ی مهم:** دوباره Confirm نکنید — State Machine (`confirmed → confirmed`) را رد می‌کند و نباید دور زده شود.
- **Diagnose:** `audit_logs` رویداد `payment.approved` و لاگ همان لحظه (کرش بین تغییر وضعیت و شارژ؟). تأیید و شارژ در یک تراکنش DB هستند؛ وجود چنین حالتی یعنی باگ یا دست‌کاری.
- **Fix:** `admin_adjust` مثبت به مبلغ Payment، توضیح: «اصلاح Payment #ID». سپس علت را پیدا کنید.
- **Gateway:** با زرین‌پال (Verify/Inquiry) تطبیق دهید که پول واقعاً گرفته شده.

## ۳) Provisioning شکست خورد
- وضعیت `provision_failed` ← بر اساس سیاست (`retry` / `refund` / `retry_then_refund`) Retry خودکار هر دقیقه (`provisioning:retry-failed`) یا Refund انجام می‌شود. **Retry بدون Debit دوباره است.**
- اگر Retry اجرا نمی‌شود: `preflight` → `retries_not_overdue` و `scheduler`. کران `schedule:run` را بررسی کنید.
- ادمین می‌تواند Retry اجباری (فراتر از سقف) یا Refund دستی بزند (صفحه‌ی سفارش). هر Refund یک‌بار مجاز است.

## ۴) پنل اکانت را ساخت ولی پاسخ نرسید (**شکاف G-9-2**)
- **رفتار فعلی:** Timeout (۱۵s) ← استثنا ← `provision_failed`. Retry **نام کاربری جدید** تولید می‌کند ← اکانت اول روی پنل **یتیم** می‌ماند و ظرفیت را اشغال می‌کند. `ProvisioningService` فقط وقتی ثبت DB شکست بخورد پاک‌سازی می‌کند (`Log::critical('orphan_panel_account')`).
- **Detect:** لاگ `orphan_panel_account`؛ اختلاف تعداد اکانت‌های پنل با `accounts`.
- **Diagnose:** روی پنل دنبال کلاینت‌هایی با `note = melorin-order-<id>` بگردید که در `accounts` نیستند.
- **Fix:** اگر Retry موفق شد، اکانت یتیم را از پنل حذف کنید؛ اگر سفارش Refund شد، حذف کنید. ظرفیت پنل را بررسی کنید.
- **اصلاح ریشه‌ای (تصمیم لازم):** پیش از Retry، پنل را با `note=melorin-order-<id>` جستجو و در صورت وجود، همان را Adopt کند.

## ۵) سفارش در `paid`/`provisioning` گیر کرده (**G-9-1 — از 3.3.8 خودکار منتقل می‌شود**)
- **خودکار (3.3.8):** `provisioning:recover-stuck` هر ۵ دقیقه سفارش `provisioning` قدیمی‌تر از ۱۵ دقیقه را به `provision_failed` می‌برد (یا اگر اکانت ثبت شده، `account_created`). **Retry خودکار برنامه‌ریزی نمی‌شود**؛ پیش از «Retry اجباری» حتماً پنل را با `note=melorin-order-<id>` بگردید، وگرنه «Refund». سفارش `paid` گیرکرده هنوز دستی است.
- **علت:** Crash/kill فرآیند بین شروع Provisioning و ثبت نتیجه. Retry خودکار فقط `provision_failed` را برمی‌دارد.
- **Detect:** `orders_not_stuck` = warn (بیش از ۱۵ دقیقه).
- **Diagnose:** `provisioning_attempts` آن سفارش (آخرین وضعیت `started`)؛ لاگ؛ و **حتماً روی پنل** وجود اکانت `melorin-order-<id>` را چک کنید.
  - اکانت روی پنل **هست** ← تحویل را دستی کامل کنید (ساخت ردیف `accounts`) یا آن را حذف و سفارش را به `provision_failed` ببرید.
  - اکانت روی پنل **نیست** ← سفارش را به `provision_failed` ببرید تا Retry/Refund عادی کار کند.
- ⚠️ این تغییر وضعیت فعلاً دستی (Tinker/DB) است و Backup قبلش الزامی است. اصلاح ریشه‌ای: Watchdog که `provisioning` قدیمی‌تر از N دقیقه را با بررسی پنل به `provision_failed` ببرد (تصمیم لازم).

## ۶) Migration شکست خورد
- `update-git.sh` Maintenance را نگه می‌دارد و Rollback کد را انجام می‌دهد؛ **Rollback Migration برای Baseline وجود ندارد** (`IRREVERSIBLE`).
- **Fix:** Restore از Backup همان Update (`DISASTER-RECOVERY.md`). سپس `migrate:status` و `preflight`.

## ۷) وب‌هوک جعلی
- بدون Secret ← ۴۰۳ (Fail-closed) و Update پردازش نمی‌شود. حجم ۴۰۳ بالا ← Rate Limit در Nginx برای `/telegram/webhook/*` و `/reseller-bot/webhook/*`.
- اگر Secret لو رفت: `TELEGRAM_WEBHOOK_SECRET` را عوض و وب‌هوک ربات اصلی را دوباره ثبت کنید؛ برای نماینده «Reconnect webhook» (Secret جدید).
- Callback جعلی زرین‌پال: بدون `Authority` درست ← ۴۰۴ و بدون تغییر وضعیت (S-03). شمار ۴۰۴های `/payment/zarinpal/callback` را پایش کنید.

## ۸) Ledger Drift (`ledger_matches_balance` / `last_balance_after_matches`)
- **Contain:** Wallet(های) درگیر را شناسایی کنید؛ تا روشن‌شدن علت `down` در صورت گستردگی.
- **Diagnose:** `SELECT * FROM wallet_transactions WHERE wallet_id=? ORDER BY id` — کدام ردیف `balance_after` را می‌شکند؟ کسی SQL مستقیم زده؟ (لاگ دسترسی DB، Audit).
- **Fix:** با Backup مقایسه کنید؛ اصلاح فقط با ردیف جبرانی `admin_adjust` و ثبت دلیل. **هرگز** ویرایش/حذف ردیف Ledger.

## ۹) Scheduler / Queue متوقف است
- `/health/ready` → `checks.scheduler`/`queue_backlog` = warn. Retry Provisioning، `guest:prune`، پایش داده متوقف است و Broadcast/اعلان‌ها گیر می‌کنند.
- `systemctl status`/Supervisor؛ `crontab -l`؛ `php artisan queue:failed`، `php artisan queue:restart`.

## ۱۰) افشای Secret (`.env`، Backup، ZIP)
1. فوراً Rotate: توکن ربات اصلی (BotFather)، `TELEGRAM_WEBHOOK_SECRET`، رمز DB، رمز ادمین، Merchant زرین‌پال، SMTP.
2. توکن ربات نمایندگان و Credentialهای پنل‌ها در DB هستند (رمز‌شده با `APP_KEY`)؛ اگر `.env` + Dump هر دو لو رفت، این‌ها هم لو رفته‌اند ← Rotate در بالادست.
3. ⚠️ `APP_KEY` را بدون Re-encrypt تغییر ندهید (ستون‌های رمز‌شده ناخواناپذیر می‌شوند).
4. نشست‌ها را باطل کنید (`php artisan tinker` ← پاک‌کردن جدول `sessions`).
