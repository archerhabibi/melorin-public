<?php

namespace App\Providers;

use App\Events\PaymentConfirmed;
use App\Listeners\NotifyUserOfPaymentConfirmation;
use App\Services\Resellers\Branding\StoreBrandResolver;
use App\Support\CurrencyLock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // B5.7: برندینگ هر فروشگاه در طول یک Request فقط یک بار از DB خوانده می‌شود؛ `scoped` یعنی بین
        // Requestها/Jobها (Worker بلندمدت) نشت نمی‌کند.
        $this->app->scoped(StoreBrandResolver::class);
    }

    public function boot(): void
    {
        // ارز/decimals بعد از Migration مبالغ قفل است (config با دیتابیس باید یکی باشد).
        CurrencyLock::verify();

        // بعد از تایید هر پرداخت (چه کارت‌به‌کارت دستی، چه درگاه آنلاین)،
        // به کاربر در تلگرام اطلاع داده شود.
        Event::listen(PaymentConfirmed::class, NotifyUserOfPaymentConfirmation::class);
    }
}
