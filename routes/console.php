<?php

use App\Services\Core\Guest\GuestCheckoutService;
use App\Services\Core\Provisioning\FailedOrderRecovery;
use App\Services\Core\Provisioning\StuckOrderWatchdog;
use App\Services\Ops\Preflight;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

// Master 2.7 G9 / DATA-RETENTION.md: حذف فیزیکی
// guest_checkouts با وضعیت expired/consumed (و pending گذشته از expires_at)
// ۶۰ روز پس از انقضا/مصرف. روزانه؛ یک Audit یک‌خطی (تعداد حذف‌شده) می‌نویسد.
Artisan::command('guest:prune', function () {
    $deleted = app(GuestCheckoutService::class)->pruneExpired();

    $this->info("deleted={$deleted}");
})->purpose('Delete guest_checkouts older than the 60-day retention window');

Schedule::command('guest:prune')->dailyAt('03:30')->withoutOverlapping(30);

// فاز ۹ — Heartbeat برای تشخیص خاموش‌بودن Scheduler (melorin:preflight و /health/ready).
Schedule::call(fn () => Cache::put(Preflight::HEARTBEAT_KEY, now()->timestamp, 900))
    ->name('melorin-scheduler-heartbeat')->everyMinute();

// فاز ۹ — پایش سلامت داده‌ی مالی/عملیاتی؛ شکست‌ها در لاگ (سطح error/warning) می‌آیند
// تا Alert روی لاگ کار کند (docs/operations/MONITORING.md).
Schedule::command('melorin:preflight --group=data --log --no-interaction')
    ->name('melorin-data-integrity')->everyFifteenMinutes()->withoutOverlapping(15);

// فاز ۹ (G-9-1) — سفارش گیرکرده در `provisioning` را به provision_failed (بدون Retry خودکار)
// یا account_created (اگر اکانت ثبت شده) می‌برد؛ حرکت مالی ندارد.
Artisan::command('provisioning:recover-stuck {--limit=50} {--minutes=15 : حداقل مدت گیرکردن}', function () {
    $summary = app(StuckOrderWatchdog::class)->run((int) $this->option('limit'), (int) $this->option('minutes'));

    $this->info(sprintf('recovered=%d marked_failed=%d', $summary['recovered'], $summary['marked_failed']));
})->purpose('Unstick orders left in provisioning after a crash (no financial movement)');

Schedule::command('provisioning:recover-stuck')->everyFiveMinutes()->withoutOverlapping(10);
