<?php

namespace App\Channels\Website;

use App\Channels\Website\Http\Middleware\EnsureCustomerAccountResolved;
use App\Channels\Website\Http\Middleware\ResolveStoreContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Provider اختصاصیِ Channel جدید «Website» — دقیقاً هم‌الگو با
 * TelegramBotServiceProvider (بند ۲ Roadmap، تقارن با ساختار موجود).
 *
 * طبق بند ۹۳ زیرسند («Website = Channel»)، این Provider و هر چیزی که
 * زیرمجموعه‌ی app/Channels/Website است فقط Adapter است: هیچ Business
 * Logic اینجا register نمی‌شود، فقط routing/middleware/view namespace.
 */
class WebsiteServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // میان‌افزارهای این Channel را با یک نام کوتاه alias می‌کنیم تا
        // در routes/website.php به‌جای FQCN کامل، فقط 'store.context' و
        // 'store.customer' نوشته شود — هم‌راستا با سبک الگوهای Laravel.
        Route::aliasMiddleware('store.context', ResolveStoreContext::class);
        Route::aliasMiddleware('store.customer', EnsureCustomerAccountResolved::class);

        $this->loadRoutesFrom(__DIR__.'/../../../routes/website.php');

        $this->loadViewsFrom(__DIR__.'/../../../resources/views/website', 'website');
    }
}
