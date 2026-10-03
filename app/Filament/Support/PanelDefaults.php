<?php

namespace App\Filament\Support;

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Layout Contract مشترک پنل‌های Filament (B1.2).
 *
 * Admin و Reseller هر دو یک‌بار از اینجا عبور می‌کنند تا «ظاهرِ پایه» (فونت، favicon،
 * استایل مشترک Design System) در یک نقطه تعریف شود و بین دو پنل نچرخد.
 * تفاوت‌های هر پنل (رنگ primary، مسیر، Tenant، گروه‌های ناوبری) در Provider خودش می‌ماند.
 */
final class PanelDefaults
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->favicon(asset('favicon.ico'))
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchDebounce('500ms')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => self::sharedStyles());
    }

    /**
     * اگر Build فرانت هنوز با این نسخه ساخته نشده باشد (manifest بدون panel.css)،
     * پنل نباید 500 بدهد؛ فقط بدون استایل اضافه بالا می‌آید.
     */
    private static function sharedStyles(): HtmlString
    {
        try {
            return app(Vite::class)(['resources/css/panel.css']);
        } catch (Throwable) {
            return new HtmlString('');
        }
    }
}
