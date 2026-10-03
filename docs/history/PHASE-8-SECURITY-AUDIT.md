# فاز ۸ — Security Audit (نسخه 3.3.6)

**روش:** مرور کد منبع (مسیرها، Middleware، Controllerهای Website/Admin/Bot، PaymentService، Gateway، Modelها، Providerهای Filament، اسکریپت‌های Deploy) + جست‌وجوی استاتیک (`whereRaw`/`DB::raw`، `{!!`، `Http::` با ورودی کاربر، `unserialize/eval/exec`، استثناهای CSRF) + تست اجرایی. **اجرای واقعی:** PHP 8.3.6، SQLite `:memory:` → **۴۹۳ سبز + ۵ Redis (با flag)**.

> ⚠️ **این Self-Audit است، نه Review مستقل.** ماتریس «Independent Security Review» عمداً ☐ مانده است.

## یافته‌های بسته‌شده

| # | شدت | یافته | اصلاح | تست |
|---|:-:|---|---|---|
| S-01 | **High** | وب‌هوک **نماینده** Fail-open: اگر `webhook_secret` خالی بود چک رد می‌شد؛ هر کس slug را می‌دانست Update جعلی می‌فرستاد (کامنت کد هم خلاف رفتار بود) | بدون secret ← ۴۰۳؛ `hash_equals` | `a_reseller_webhook_without_any_stored_secret…` |
| S-02 | **High** | وب‌هوک **اصلی** Fail-open وقتی `TELEGRAM_WEBHOOK_SECRET` خالی؛ مقایسه‌ی غیرزمان‌ثابت | Fail-closed + `hash_equals` | `the_main_webhook_*` (۲) |
| S-03 | **High** | Callback زرین‌پال بدون احراز و `payment_id` ترتیبی: `?payment_id=N&Status=NOK` پرداخت Pending **هر کاربری** را Reject می‌کرد؛ اگر قربانی بعد پرداخت می‌کرد، پول کسر ولی کیف‌پول شارژ نمی‌شد (`assertPending` پرتاب می‌شد) | `Authority` باید با `gateway_reference` یکی باشد (`hash_equals`) وگرنه 404 عمومی بدون تغییر وضعیت؛ Throttle ۳۰/دقیقه | ۴ تست (Forged NOK / Forged OK / قربانی هنوز می‌تواند پرداخت کند / NOK واقعی) |
| S-04 | Med | `TrustProxies` تنظیم نبود ← پشت Nginx/cloudflared همه‌ی کاربران یک IP (لوپ‌بک) ← Rate Limitهای IP-محور (لاگین ۵/دقیقه…) مشترک (DoS)، IP Audit نادرست، `isSecure()` غلط | `TRUSTED_PROXIES` (پیش‌فرض لوپ‌بک)، فقط هدرهای X-Forwarded-* | ۲ تست |
| S-05 | Med | `POST /register` بدون Throttle ← ثبت‌نام انبوه + ایمیل‌بمب | `throttle:10,60` | ۱ تست |
| S-06 | Low | `ResellerLoginController` بعد از `login` شناسه‌ی نشست را عوض نمی‌کرد (Fixation) | `session()->regenerate()` | (پوشش تست‌های موجود) |
| S-07 | Med (مالی) | تمدید Website بدون Idempotency Key ← کلید از «ثانیه‌ی جاری» ← دو کلیک/Refresh = دو بار کسر | توکن یکتا در فرم؛ بدون توکن: پنجره‌ی ۱۰ثانیه‌ای | ۲ تست |
| S-08 | Med | فقط CSP روی Website؛ هیچ `nosniff`/`Referrer-Policy`/`X-Frame-Options`/`Permissions-Policy`/HSTS؛ پنل‌های Filament بی‌هدر | `SecurityHeaders` روی `web` + هر دو پنل؛ HSTS فقط HTTPS؛ CSP `object-src 'none'` | ۳ تست |
| S-09 | Low | آپلود مجدد رسید فایل قبلی را پاک نمی‌کرد (رشد دیسک) | حذف فایل قبلی | ۱ تست |
| S-10 | Low | پروکسی رسید ادمین برای نوع ناشناخته `Content-Disposition` نمی‌داد | `attachment` برای `octet-stream` | — |

## کنترل‌هایی که بررسی شد و سالم بود
IDOR روی Order/Account/Receipt/Payment (همه Scope با CustomerAccount + `reseller_id`، پاسخ 404) · CSRF (هیچ استثنایی؛ وب‌هوک‌ها خارج گروه `web` و با Secret) · XSS (هیچ `{!!` در Viewها؛ لوگو فقط png/jpg/webp) · SQL Injection (همه‌ی `*Raw` رشته‌ی ثابت، بدون ورودی کاربر) · SSRF (URL پنل‌ها فقط توسط ادمین) · RCE (`eval/exec/unserialize` وجود ندارد) · Mass Assignment (ورودی‌ها از `validated()`؛ `User::$fillable` فقط در کد سرور) · Telegram Link (State یک‌بارمصرف + HMAC + `auth_date` + مالکیت) · Password Reset (بدون Enumeration، ۳/ساعت per-email) · Open Redirect (`intended` فقط از Session سمت سرور) · Session (`HttpOnly`, `Secure` از env, regenerate بعد از Login/Register) · آپلود رسید (محتوای واقعی، ۵MB، `local` خصوصی، نوع Allow-list شده در نمایش).

## یافته‌های باز (نیاز به تصمیم/محیط واقعی؛ در این پچ نیستند)
| # | شدت | موضوع | پیشنهاد |
|---|:-:|---|---|
| O-1 | **High** | **Admin Authorization تخت است:** `Admin::canAccessPanel()` همیشه `true`؛ `is_super_admin` هیچ‌جا خوانده نمی‌شود؛ Policy وجود ندارد. هر ادمین می‌تواند Refund، تنظیم دستی موجودی، دیدن توکن‌ها و … | تصمیم محصول: Matrix نقش‌ها (Super/Server/Support) + Policy روی Resourceهای مالی/زیرساخت. فاز جدا. |
| O-2 | Med | توکن ربات اصلی داخل URL وب‌هوک (`/telegram/webhook/{bot_token}`) ← در Access Log Nginx/CDN | Path-token جدا (نیازمند ثبت مجدد وب‌هوک) یا Scrub لاگ |
| O-3 | Med | ادمین 2FA ندارد | Filament MFA / محدودسازی IP پنل |
| O-4 | Low | Guest Form با ایمیل موجود به Login هدایت می‌کند (Enumeration) — طراحی‌شده (R9)، با Throttle ۱۰/دقیقه | پذیرفته‌شده؛ ثبت در Threat Model |
| O-5 | Low | Route `reseller.login` (Signed) فعال است ولی در کد هیچ‌جا لینکش ساخته نمی‌شود (سطح حمله‌ی بلااستفاده) | حذف یا بازگرداندن تولید لینک با Nonce یک‌بارمصرف |
| O-6 | Low | `Log::info('telegram_update_resolved')` متن/Callback هر پیام کاربر را لاگ می‌کند (PII) | کوتاه‌سازی/حذف `text_or_data` در Production |
| O-7 | Low | `style-src 'unsafe-inline'` (بدهی شناخته‌شده در Contract) | Nonce/Hash |
| O-8 | — | **قابل‌سنجش فقط روی Staging:** Sandbox واقعی زرین‌پال، Provisioning واقعی/Timeout/پاسخ گمشده، Concurrency چندپردازه، `request()->ip()` واقعی پشت Tunnel، Rollback/Restore | فاز ۹ |

## ورودی فاز ۹ (Staging)
۱) `.env`: `TELEGRAM_WEBHOOK_SECRET` حتماً مقدار داشته باشد (وگرنه ربات اصلی ۴۰۳ می‌دهد). ۲) نمایندگانِ قدیمیِ بدون secret: «Reconnect webhook» از پنل ادمین (یا `ensureWebhookSecret` + `setWebhook`). ۳) `TRUSTED_PROXIES` را با `request()->ip()` واقعی چک کنید. ۴) HSTS فقط پس از اطمینان از HTTPS کامل. ۵) O-1 را قبل از Production تصمیم بگیرید.
