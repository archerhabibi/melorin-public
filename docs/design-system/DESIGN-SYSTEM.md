# Melorin Design System — B1.1

وضعیت قبل: Tailwind خام (`bg-white border rounded-lg`, `text-gray-500`…) تکرار شده در ~۲۵ View، بدون فونت فارسی، بدون توکن، بدون آیکون.

## لایه‌ها
| لایه | فایل | نقش |
|---|---|---|
| Tokens | `resources/css/design/tokens.css` | رنگ/سایه/شعاع (Light + آمادهٔ Dark) |
| Tailwind map | `tailwind.config.js` | `bg-surface`, `text-muted`, `border-border`, `bg-success-soft`… |
| Components (CSS) | `resources/css/app.css` | `.btn .input .card .alert .badge .table .page-title` |
| Components (Blade) | `resources/views/components/ui/*` | `<x-ui.button|field|alert|errors|badge|card|stat|page-header|empty-state|icon>` |
| Typography | `@fontsource-variable/vazirmatn` | Self-hosted (سازگار با CSP: `font-src 'self'`) |

## قواعد
1. رنگ خام ممنوع (`gray-*`, `red-*`, `#fff`) → فقط توکن.
2. رنگ Brand نماینده فقط از `--brand` (Layout تزریق می‌کند؛ `--brand: #xxxxxx` دست نخورده). `btn-primary`, focus ring و `link-brand` از آن مشتق می‌شوند.
3. آیکون جدید = یک ردیف در `components/ui/icon.blade.php`. بدون CDN.
4. JS تعاملی باید CSP-safe باشد (بدون inline/eval). نمونه: `data-copy="#id"`.
5. وضعیت‌ها: success / warning / danger / info + neutral (Badge و Alert).

## مهاجرت Viewها
کلاس‌های قدیمی هنوز کار می‌کنند (چیزی نشکسته). جدول تبدیل:
| قدیمی | جدید |
|---|---|
| `bg-white border rounded-lg p-6` | `<x-ui.card>` |
| `text-gray-500 hover:text-gray-900` | `nav-link` / `link` |
| بلوک `@if($errors->any())` | `<x-ui.errors />` |
| `label + input w-full rounded border-gray-300…` | `<x-ui.field name= label= />` |
| `style="background: var(--brand)"` + `text-white` | `<x-ui.button>` |
| `bg-red-50 border-red-200 text-red-800` | `<x-ui.alert type="danger">` |

مهاجرت‌شده در B1.1: `layouts/app`, `account/_nav`, `auth/login`, و دکمه‌های کپی (`referral`, `accounts/show`).
مانده: بقیهٔ Viewهای `website/*` (~۲۰ فایل) — مکانیکی، با جدول بالا؛ پیشنهاد: هر Layout در B1.2 که لمس شد، همان‌جا مهاجرت شود.

## عمداً در B1.1 نیست
- سوئیچ Light/Dark و ذخیرهٔ انتخاب → B1.4 (توکن‌های Dark آماده‌اند).
- Layout Admin/Reseller/Customer → B1.2.
- Contrast خودکار برای رنگ Brand روشن (متن سفید روی Brand زرد) → B1.4/B6.2.
- تم Filament (پنل ادمین) → B7.

---
# B1.2 — Layout System

سه Layout، یک زبان بصری (همه از `tokens.css` + Vazirmatn):

| Layout | پیاده‌سازی | Extension point |
|---|---|---|
| Customer (+ Reseller Website) | `website/layouts/app.blade.php` + `website/account/_nav.blade.php` | `@section('container')` (عرض)، `@push('head'|'scripts')`، آیتم جدید پنل = یک ردیف در `$items` |
| Admin (Filament) | `AdminPanelProvider` → `PanelDefaults::apply()` | `resources/css/panel.css` |
| Reseller Panel (Filament) | `ResellerPanelProvider` → `PanelDefaults::apply()` | همان `panel.css` |

- `PanelDefaults` تنها نقطهٔ «ظاهر پایه» هر دو پنل است (favicon + استایل مشترک). اگر Build فرانت قدیمی باشد، پنل بدون استایل اضافه بالا می‌آید و 500 نمی‌دهد.
- Layout وب: Skip-link، `<main id="main">`، `aria-current` در منو، منوی پنل کاربری اسکرول‌پذیر در موبایل.
- Brand نماینده روی Customer Layout (همان `--brand`) دست‌نخورده است؛ روی پنل Filament نماینده عمداً در B1.4/B6.2.

---
# B1.3 — Navigation Contract

## قبل از B1.3 چه بود (حفظ شد)
گروه‌بندی منوی Filament (Admin و Reseller)، آیکون و برچسب فارسی همهٔ Resourceها، و بج تیکت/سفارش‌های ناموفق/شارژهای نماینده از قبل بود.

## چه بهبود یافت
| بخش | قرارداد |
|---|---|
| Admin nav | گروه «گزارشات» (Reports + سوابق عملیات) در `navigationGroups` ثبت نبود → ثبت شد. همهٔ Resource/Pageها `navigationSort` صریح دارند (قبلاً به ترتیب فایل‌سیستم). |
| Reseller nav | «پیام همگانی» بدون گروه بود → گروه «پیام‌رسانی». |
| Badge | پرداخت‌های `pending` در ادمین (هم‌الگو با پنل نماینده). |
| Search (Filament) | میانبر `Ctrl/⌘+K` و debounce برای هر دو پنل (`PanelDefaults`). Admin: کاربران (نام/ایمیل/موبایل/تلگرام)، سفارش‌ها، پرداخت‌ها، تیکت‌ها، نمایندگان. Reseller: فقط مشتریان؛ چون `getEloquentQuery` آن Resource به فروشگاه همان نماینده اسکوپ است، جستجو نشت cross-tenant ندارد. |
| Website menu | آیتم «پروفایل» به منوی پنل کاربری اضافه شد (صفحه وجود داشت ولی از هیچ‌جا لینک نبود). هدر برای کاربر واردشده نام را به لینک «پنل من» تبدیل کرد. |
| Breadcrumb | `<x-ui.breadcrumb>`؛ در `_nav` به‌صورت خودکار برای همهٔ صفحات پنل کاربری (خانه › حساب من › بخش). صفحهٔ عمیق‌تر: `@include('website.account._nav', ['crumbs' => [['label' => '...']]])`. |

## قواعد برای Resource جدید
1. `navigationGroup` فقط از گروه‌های ثبت‌شده در Provider همان پنل؛ `navigationSort` الزامی.
2. جستجوی سراسری فقط وقتی صفحهٔ `view`/`edit` دارد و `getEloquentQuery` اسکوپ‌شده است. فیلد حساس (توکن، secret) هرگز searchable نیست.
3. آیتم جدید منوی مشتری = یک ردیف در `$items` فایل `_nav.blade.php`.

## الگوی جستجو در Website
فعلاً جستجوی سراسری ندارد (فهرست‌ها کوتاه‌اند). وقتی لازم شد: فرم GET با `?q=` روی همان فهرست، و Query در Service Core؛ هیچ منطق جستجو در Controller/View نمی‌رود.

---
# B1.4 — Theme Engine

## Light / Dark
- توکن‌های Dark از B1.1 آماده بودند؛ B1.4 سوئیچ و ذخیره‌سازی را اضافه کرد.
- `public/js/theme-init.js` (همگام در `<head>`، بدون فلاش) → `data-theme` را از `localStorage['melorin-theme']` یا `prefers-color-scheme` می‌گذارد. فایل جداست چون CSP اجازهٔ inline script نمی‌دهد.
- دکمهٔ `data-theme-toggle` در هدر. بدون JS، پیش‌فرض Light.
- `color-scheme` روی `:root` است تا فرم‌ها/اسکرول‌بار هم‌رنگ شوند.

## مهاجرت Viewها (پیش‌نیاز Dark)
همهٔ Viewهای `website/*` به توکن مهاجرت شدند (`bg-white→bg-surface`، `text-gray-*→text-muted/text`، رنگ‌های alert→`danger/success/warning`، inputها→`.input`، `style="background: var(--brand)"→bg-brand text-on-brand`). `ThemeEngineTest` جلوی بازگشت رنگ خام را می‌گیرد. اثر جانبی: بیشتر inline style ها حذف شد؛ فقط بلوک `:root{--brand}` در Layout می‌ماند (دلیل `style-src 'unsafe-inline'` در CSP).

## Reseller Branding
| سطح | رفتار |
|---|---|
| Website | `--brand` و `--brand-contrast` (سفید یا تیره بر اساس WCAG، `BrandColor::onColor`). برند زرد → متن دکمه تیره می‌شود. در Dark، متن/لینک برند ۴۰٪ به سفید روشن می‌شود تا برند تیره روی سطح تیره گم نشود. |
| پنل Filament نماینده | `primary` از همان رنگ Brand (`ResellerPanelProvider`، closure روی Tenant). چون Filament متن سفید دارد، رنگ روشن با `BrandColor::readableWithWhite` تا contrast ≥ ۳ تیره می‌شود. |
| پنل ادمین | رنگ ثابت Melorin (Indigo)، عمداً بدون Brand نماینده. |

Dark خود Filament را همان سوئیچر داخلی Filament مدیریت می‌کند (منوی کاربر)؛ ادغام با `melorin-theme` لازم نیست چون صفحات Filament و Website یک origin ولی دو نمای جدا هستند.
