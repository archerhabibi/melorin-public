<?php

namespace App\Providers\Filament;

use App\Filament\Support\PanelDefaults;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Support\Branding\BrandColor;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * پنل وب نماینده (R5 سند معماری Reseller Platform، بخش ۱۴).
 *
 * دو تصمیم مهم طبق درخواست صریح:
 * ۱) ->path('') + ->tenant(Reseller::class, slugAttribute: 'slug'):
 *    یعنی آدرس هر نماینده دقیقاً «آدرس‌سایت/نام‌لاتین‌خودش» است
 *    (مثلاً /parismobile)، نه یک مسیر ثابت مشترک مثل /reseller. این
 *    مسیر با '/' خالیِ routes/web.php تداخل ندارد چون آن route هیچ
 *    پارامتری نمی‌گیرد ولی مسیر Tenant همیشه به یک slug واقعی نیاز دارد.
 * ۲) ->login(): قبلاً این پنل فقط از طریق لینک یک‌بارمصرفِ ربات وارد
 *    می‌شد و هیچ صفحه‌ی ورودی نداشت؛ همین نبودِ صفحه‌ی ورود، دقیقاً
 *    همان باگِ گزارش‌شده («ورود به پنل → 403 خام») را ایجاد می‌کرد،
 *    چون middleware احراز هویتِ Filament وقتی صفحه‌ی ورودی برای
 *    redirect پیدا نکند، به‌جای هدایت به صفحه‌ی لاگین مستقیم abort(403)
 *    می‌دهد. حالا با ایمیل/رمزعبور (که در زمان ساخت نماینده در پنل
 *    ادمین تنظیم می‌شود) واقعاً یک صفحه‌ی ورود وجود دارد.
 * لینک یک‌بارمصرفِ ربات (ر.ک. ResellerLoginController) هم‌چنان به‌عنوان
 * یک میان‌بر اضافه کار می‌کند، جایگزین ایمیل/رمز نیست.
 */
class ResellerPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/reseller-panel.php'));
    }

    public function panel(Panel $panel): Panel
    {
        return PanelDefaults::apply($panel)
            ->id('reseller')
            ->path('')
            // مسیر ورود پنل نماینده عمداً '/panel/login' است نه '/login':
            // چون path('') است، '/login' پیش‌فرض Filament دقیقاً با
            // GET /login سایت فروش (website.login) یکسان می‌شد و Laravel
            // در RouteCollection مسیر دوم را بی‌صدا جایگزین اولی می‌کرد
            // (کلید method+uri) → Route [filament.reseller.auth.login]
            // not defined. '/panel/login' هنوز شامل '/login' است.
            ->login()
            ->loginRouteSlug('panel/login')
            ->authGuard('reseller')
            // ownershipRelationship صریح لازم است: Filament پیش‌فرض دنبال
            // User::reseller() می‌گردد (از نام کلاس تننت مشتق می‌شود)،
            // ولی فاز ۱۵ آن رابطه‌ی تک‌مقداری را از User حذف کرد (چون
            // دیگر با مدل «مشتریِ چند نماینده» سازگار نبود — ر.ک.
            // MembershipGuardTest::the_user_model_no_longer_exposes_a_single_reseller).
            // رابطه‌ی درست برای صاحبِ نماینده (کسی که با User خودش وارد
            // همین پنل می‌شود) همان User::resellerAccount() است.
            ->tenant(Reseller::class, slugAttribute: 'slug', ownershipRelationship: 'resellerAccount')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            // B1.4: رنگ اصلی پنل = رنگ Brand خودِ نماینده (همان منبعی که Website می‌خواند).
            // Closure است چون Tenant فقط هنگام رندر مشخص است؛ در صفحه‌ی ورود (بدون Tenant) پیش‌فرض می‌ماند.
            ->colors(fn (): array => ($tenant = Filament::getTenant())
                ? ['primary' => BrandColor::readableWithWhite(ResellerWebsiteSetting::brandingFor($tenant)['color'])]
                : [])
            // نامِ برند در پنل نماینده، نامِ خودِ نماینده است نه
            // «ملورین» — نماینده این پنل را به‌عنوان فروشگاه خودش
            // می‌بیند. از Tenant فعلی خوانده می‌شود، و در صفحه‌ی ورود
            // (که هنوز Tenant حل نشده) به یک نام عمومی سقوط می‌کند.
            ->brandName(fn () => Filament::getTenant()?->getFilamentName() ?? 'پنل نمایندگی')
            ->navigationGroups([
                'فروشگاه من',
                'مشتریان و فروش',
                'مالی',
                'پیام‌رسانی',
                'تنظیمات',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Reseller/Resources'), for: 'App\\Filament\\Reseller\\Resources')
            ->discoverPages(in: app_path('Filament/Reseller/Pages'), for: 'App\\Filament\\Reseller\\Pages')
            // داشبورد نماینده (B5.1) = App\Filament\Reseller\Pages\Dashboard که با discoverPages بالا
            // پیدا می‌شود؛ Pages\Dashboard پیش‌فرض Filament عمداً ثبت نمی‌شود تا دو داشبورد با یک مسیر نباشد.
            ->discoverWidgets(in: app_path('Filament/Reseller/Widgets'), for: 'App\\Filament\\Reseller\\Widgets')
            ->middleware([
                SecurityHeaders::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
