<?php

namespace App\Providers\Filament;

use App\Models\Reseller;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
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
        return $panel
            ->id('reseller')
            ->path('')
            ->login()
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
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Reseller/Widgets'), for: 'App\\Filament\\Reseller\\Widgets')
            ->middleware([
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
