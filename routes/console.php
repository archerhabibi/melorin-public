<?php

use App\Services\Core\Provisioning\FailedOrderRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// فاز ۱۱ — تلاش مجدد خودکار برای سفارش‌هایی که بعد از پرداخت، ساخت/تمدید
// سرویس‌شان شکست خورده (سیاست‌های retry و retry_then_refund). کران‌جاب
// `schedule:run` هر دقیقه توسط install.sh نصب شده است.
Artisan::command('provisioning:retry-failed {--limit=25 : حداکثر تعداد سفارش در هر اجرا}', function () {
    $summary = app(FailedOrderRecovery::class)->runDueRetries((int) $this->option('limit'));

    $this->info(sprintf(
        'attempted=%d succeeded=%d failed=%d skipped=%d refunded=%d',
        $summary['attempted'], $summary['succeeded'], $summary['failed'], $summary['skipped'], $summary['refunded'],
    ));
})->purpose('Retry provisioning/renewal of paid orders whose panel call failed');

Schedule::command('provisioning:retry-failed')->everyMinute()->withoutOverlapping(10);
