<?php

namespace App\Providers;

use App\Events\PaymentConfirmed;
use App\Listeners\NotifyUserOfPaymentConfirmation;
use App\Support\CurrencyLock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // فاز ۵: ارز/decimals بعد از Migration مبالغ قفل است (config با دیتابیس باید یکی باشد).
        CurrencyLock::verify();

        // بعد از تایید هر پرداخت (چه کارت‌به‌کارت دستی، چه درگاه آنلاین)،
        // به کاربر در تلگرام اطلاع داده شود.
        Event::listen(PaymentConfirmed::class, NotifyUserOfPaymentConfirmation::class);
    }
}
