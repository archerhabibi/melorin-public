<?php

namespace App\Providers\Filament;

use App\Models\Admin;
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
 * پنل مدیریت تحت وب (بند ۲۲ سند نیازمندی). طبق بند ۳۴، این پنل هم مثل
 * ربات یک Adapter است — مستقیم به Core Services (WalletService,
 * AccountService, PaymentService, ...) وصل می‌شود، نه به دیتابیس خام.
 *
 * از مدل Admin (نه User) برای احراز هویت استفاده می‌شود؛ چون کاربران
 * عادی (User) نباید به این پنل دسترسی داشته باشند — طبق بند ۲۳، سطح
 * دسترسی فقط بین ادمین‌ها (Super Admin / Server Admin / Support Admin)
 * تعریف می‌شود.
 */
class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/admin.php'));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('admin')
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->brandName('ملورین')
            // چیدمان ناوبری: بدون این، Filament همه‌ی Resourceها را در
            // یک لیست تخت الفبایی می‌ریزد و با رشد پنل (کاربران،
            // نمایندگان، سرورها، مالی، پشتیبانی، ...) پیدا کردن هر چیز
            // سخت می‌شود. ترتیب گروه‌ها از «کار روزمره» به «تنظیمات
            // زیرساخت» است.
            ->navigationGroups([
                'فروشگاه',
                'کاربران',
                'نمایندگان',
                'مالی',
                'زیرساخت',
                'پشتیبانی',
                'پیام‌رسانی',
                'تنظیمات',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
