# Reseller Custom Domain Contract (B6.1)

> نسخه 1.1 · Parent: Master 2.8 / Website 1.9 / Reseller White Label 1.0 · وضعیت: CANONICAL
> دامنه: دامنه‌ی اختصاصی فروشگاه نماینده (مسیریابی بر پایه‌ی Host، تأیید مالکیت، مجوز TLS). **Migration کوچک و افزایشی** (`2026_10_08_000001`: شش ستون روی `reseller_website_settings`) و **بدون تغییر Purchase/Provisioning/Refund/Wallet/Payment/StoreContext/ResolveStoreContext**. Website همچنان فقط Channel است.

## D1. معماری
- **مسیریابی = بازنویسی داخلیِ مسیر، نه Route جدید.** `RouteCustomDomainRequests` (Middleware سراسری، بعد از TrustProxies و قبل از مسیریابی): اگر Host یک دامنه‌ی **تأییدشده** باشد، مسیر به `/store/{slug}{path}` بازنویسی می‌شود؛ همان Routeها، همان `ResolveStoreContext` و همان Controllerها سرویس می‌دهند. هیچ منطق فروشگاهی تکرار نشده.
- **URL خروجی:** `route()` روی همان Host و **بدون** `/store/{slug}` چاپ می‌شود (`URL::forceRootUrl` + `formatPathUsing`)، پس هیچ View/Controllerی تغییر نکرد. پس از پاسخ (`terminate`) وضعیت URL Generator برمی‌گردد.
- Host پلتفرم (`APP_URL`)، Host ناشناخته، دامنه‌ی تأییدنشده یا `MELORIN_CUSTOM_DOMAINS=false` ⇒ **دقیقاً رفتار قبل از B6.1**. نماینده‌ی غیرفعال ⇒ 404. خطای DB در تشخیص Host درخواست را نمی‌شکند.
- روی دامنه‌ی اختصاصی فقط مسیرهای فروشگاه سرو می‌شود: `/store/x/...` و پنل Filament (`/{slug}`) آنجا 404 است. مسیرهای زیرساختی بازنویسی نمی‌شوند: `/up`، `/health/*`، `/payment/*` (بازگشت از درگاه)، `/build`، `/storage`، `favicon.ico`، `robots.txt`.
- آدرس قبلی `/store/{slug}` روی Host پلتفرم همچنان کار می‌کند (Redirect کانونیکال → B6.5).

## D2. `ResellerDomainService` (تنها مسیر)
- **نرمال‌سازی:** حروف کوچک، حذف scheme/path/query/نقطه‌ی پایانی؛ رد: خالی، تک‌برچسب، IP، پورت/`@`، wildcard، برچسب نامعتبر/بلندتر از ۶۳، پسوند رزروشده (`local/localhost/internal/lan/…`)، دامنه‌ی خودِ پلتفرم و **زیردامنه‌هایش**. IDN فقط با punycode یا افزونه‌ی intl.
- **یکتایی:** یک دامنه در کل پلتفرم (قید یکتا در DB + چک پیش از ذخیره)؛ پیام «اشغال است» مالک را فاش نمی‌کند.
- **تأیید مالکیت:** TXT روی `_melorin-verify.{domain}` (قابل‌تنظیم) با مقدار = توکن تصادفی ۴۰ نویسه‌ای؛ مقایسه‌ی `hash_equals`، نقل‌قول‌های اطراف پذیرفته می‌شود. فقط `verified` مسیریابی می‌شود.
- **تغییر دامنه** تأیید را باطل و توکن را عوض می‌کند. **همان دامنه** دوباره ⇒ بدون نوشتن/Audit. **حذف** همه‌ی ستون‌ها را پاک می‌کند.
- **Audit:** `reseller.domain.set | verified | removed` با کنشگر؛ توکن هرگز ثبت نمی‌شود.
- DNS پشت `DnsTxtResolver` (قابل Fake در تست).

- **ادعای تأییدنشده (Pending TTL):** هر ثبت `claimed_at` می‌گیرد. ادعای `pending` پس از `MELORIN_DOMAIN_PENDING_TTL_HOURS` (پیش‌فرض ۷۲) آزاد می‌شود (`reseller.domain.expired`) و نماینده‌ی دیگر می‌تواند همان دامنه را ثبت کند؛ این جلوگیری از «اشغال» دامنه‌ی دیگران با ثبت بی‌اثبات است. ادعای `verified` یا تازه هرگز گرفته نمی‌شود.
- **بازبررسی/تأیید خودکار:** `melorin:domains:check` (هر ۱۰ دقیقه، `withoutOverlapping`): ① `pending` با دیده‌شدن TXT خودکار `verified` می‌شود (بدون کلیک)؛ ② `verified` هر `MELORIN_DOMAIN_RECHECK_HOURS` (۶) دوباره بررسی می‌شود و `checked_at` = «آخرین بار که TXT دیده شد»؛ ③ اگر TXT بیش از `MELORIN_DOMAIN_LOST_GRACE_HOURS` (۷۲) دیده نشود، دامنه به `pending` برمی‌گردد (`reseller.domain.lost`): مسیریابی و مجوز TLS قطع، ادعا حفظ و TTL تازه. Grace برای خطای گذرای DNS است (Resolver «خطا» و «نبودن» را یکی می‌بیند). DNS بیرون از Transaction خوانده و نتیجه فقط وقتی روی ردیف قفل‌شده اعمال می‌شود که دامنه/توکن عوض نشده باشد (`skipped`).
- **لغو ادمین:** `revoke(Reseller, Admin)` (اکشن «لغو دامنه‌ی اختصاصی» در `ResellerResource`؛ ستون «دامنه‌ی اختصاصی» هم هست): همان حذف با Audit `reseller.domain.revoked` و کنشگر `admin` (سوءاستفاده/فیشینگ/پشتیبانی).
- **کش Host:** فقط `Host → reseller_id` (و «نبودن») `MELORIN_DOMAIN_CACHE_TTL` (۶۰ ثانیه، ۰ = خاموش) کش می‌شود و با هر تغییر وضعیت از طریق سرویس باطل می‌شود؛ خودِ نماینده/وضعیت فعال‌بودنش همیشه تازه خوانده می‌شود (غیرفعال‌سازی فوری است). Hostِ بی‌شکلِ دامنه‌نما (کاراکتر نامجاز/بلندتر از ۲۵۳) بدون DB و کش رد می‌شود.

## D3. UI (`/store/{slug}/manage/domain`)
ثبت، نمایش رکورد TXT، «بررسی DNS»، حذف. دسترسی همان `ManageController::authorize` (ادمین/مالک همین نماینده؛ دیگران 403). نوشتن‌ها throttle دارند (verify: ۶/دقیقه). لینک «دامنه» در منوی نماینده. UI یادآوری می‌کند DNS خودکار بررسی می‌شود، مهلت انقضای ادعای pending را نشان می‌دهد و می‌گوید TXT باید باقی بماند.

## D4. مجوز TLS
`GET /health/domain-allowed?domain=…[&token=…]` ⇒ 200 فقط برای دامنه‌ی تأییدشده‌ی نمایندگیِ فعال، وگرنه 404؛ با `MELORIN_DOMAIN_ASK_TOKEN` توکن الزامی می‌شود. نمونه‌ی Caddy:
```
{ on_demand_tls { ask https://PLATFORM/health/domain-allowed?token=SECRET } }
https:// { tls { on_demand }  reverse_proxy 127.0.0.1:8080 }
```
Nginx/Cloudflare: Host را به Laravel پاس دهید (`proxy_set_header Host $host`) و `TRUSTED_PROXIES` را تنظیم کنید. `SESSION_DOMAIN` پلتفرم روی دامنه‌ی اختصاصی اعمال نمی‌شود (Cookie هر Host جداست).

## D5. تصمیم‌ها / محدودیت‌ها (صادقانه)
- **Google Login روی دامنه‌ی اختصاصی غیرفعال است** (دکمه پنهان، مسیر 404): Redirect URI گوگل ثابت و روی Host پلتفرم است و Session هر Host جدا؛ برگشت به نشست اصلی ممکن نیست. ورود با ایمیل کار می‌کند. طراحی جداگانه لازم است (Handoff امضاشده).
- ورود/Session مشتری بین Host پلتفرم و دامنه‌ی اختصاصی **مشترک نیست** (عمدی؛ ایزولاسیون).
- لینک‌های امضاشده‌ی ایمیل (تأیید ایمیل) Main-only‌اند و روی Host پلتفرم باز می‌شوند.
- دکمه‌ی «بررسی DNS» باقی است؛ بازبررسی دوره‌ای در D2 است. **باقی‌مانده:** ادعای pending تازه (<TTL) دامنه را برای دیگران قفل می‌کند (برای رفع کامل باید چند ادعای هم‌زمان مجاز باشد: قید یکتا فقط روی verified؛ نیازمند ستون/ایندکس مولد در MySQL). ادمین با «لغو» می‌تواند آزاد کند.
- کش Host در چند سرور با Driver محلی (file/array) تا `cache_ttl` ثانیه تأخیر دارد؛ Redis/Database مشترک بهتر است.
- گواهی TLS قبلاً صادرشده با Demote باطل نمی‌شود؛ فقط صدور/تمدید جدید و مسیریابی قطع می‌شود (مدیریت گواهی با پراکسی است).
- یک دامنه برای هر نماینده؛ Redirect www↔apex، Canonical، sitemap/OG = B6.5.

## D6. تست
`CustomDomainTest` (۵۹ مورد): نرمال‌سازی (۱۴ ورودی نامعتبر)، یکتایی، چرخش توکن، تأیید DNS، حذف، مسیریابی Host، لینک‌های بدون `/store`، ۴۰۴ برای پیشوند/پنل، دامنه‌ی تأییدنشده/ناشناخته، نماینده‌ی غیرفعال، Feature Flag، بازگشت URL Generator، Redirect مهمان به ورود همان دامنه، دو دامنه بدون اختلاط، Endpoint TLS (+توکن)، UI و مجوزها، Google، Idempotency Migration (شش ستون) **و (B6.1 تکمیل):** تأیید خودکار توسط Scheduler، انقضا و تصاحبِ ادعای قدیمیِ pending (و عدم تصاحب verified/تازه)، فاصله‌ی بازبررسی، Grace (حفظ) و Demote پس از Grace (قطع مسیریابی + TLS)، بازیابی پس از برگشتن TXT، نتیجه‌ی کهنه (`skipped`)، Feature Flag، دستور Artisan، کش Host (ابطال با تغییر، نبودن، غیرفعال‌سازی نماینده، Hostِ بی‌شکل)، لغو ادمین (Audit با کنشگر admin) از طریق Livewire، متن UI.
**اجرا شد:** کل مجموعه روی SQLite: ۱۲۵۱ سبز، ۵ Skip. **اجرا نشده:** MySQL واقعی، DNS/TLS/پراکسی واقعی، مرورگر.
