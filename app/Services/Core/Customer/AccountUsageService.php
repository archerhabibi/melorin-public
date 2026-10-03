<?php

namespace App\Services\Core\Customer;

use App\Models\Account;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\SupportsUsageReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * B3.2 — هم‌گام‌سازی «مصرف» سرویس‌ها از پنل (Core؛ Website/Bot فقط صدا می‌زنند).
 *
 * چرا لازم بود: تا B3.1 ستون traffic_used_gb فقط هنگام ساخت/تمدید صفر می‌شد و هیچ‌چیز آن را از پنل
 * نمی‌خواند؛ پس «حجم باقی‌مانده»، نوار مصرف و هشدار ۹۰٪ داشبورد همیشه مصرفِ صفر را نشان می‌داد.
 *
 * قواعد:
 *  - فقط خواندن از پنل (getAccount) + نوشتن دو ستونِ مصرف؛ هیچ‌چیزِ مالی/وضعیتی را تغییر نمی‌دهد.
 *  - شکستِ پنل هرگز عددِ قبلی را خراب نمی‌کند (پاسخِ بدون مصرف ⇒ FAILED، نه صفر).
 *  - تلاشِ دستی مشتری Throttle دارد (یک پنل خارجی نباید با Refresh پشت‌سرهم فشار ببیند).
 */
class AccountUsageService
{
    /** حداقل فاصله‌ی دو بار صدا زدن پنل برای یک سرویس (تلاش دستی مشتری). */
    public const THROTTLE_SECONDS = 120;

    public function refresh(Account $account, bool $force = false): UsageRefreshResult
    {
        if ($account->status !== 'active') {
            return UsageRefreshResult::of(UsageRefreshResult::SKIPPED);
        }

        $panel = $account->serverPanel;

        if (! $panel || ! $account->panel_username) {
            return UsageRefreshResult::of(UsageRefreshResult::FAILED);
        }

        try {
            $driver = PanelDriverFactory::make($panel->panel_type);
        } catch (\InvalidArgumentException) {
            return UsageRefreshResult::of(UsageRefreshResult::UNSUPPORTED);
        }

        if (! $driver instanceof SupportsUsageReport) {
            return UsageRefreshResult::of(UsageRefreshResult::UNSUPPORTED);
        }

        // Cache::add اتمیک است: دو درخواست هم‌زمان فقط یکی‌شان به پنل می‌رسد.
        if (! $force && ! Cache::add($this->throttleKey($account), 1, self::THROTTLE_SECONDS)) {
            return UsageRefreshResult::of(UsageRefreshResult::THROTTLED);
        }

        try {
            $result = $driver->getAccount($panel, $account->panel_username);
            $bytes = $result->success ? $driver->usedTrafficBytes($result) : null;
        } catch (\Throwable $e) {
            Log::warning('usage_refresh_failed', [
                'account_id' => $account->id,
                'panel_id' => $panel->id,
                'error' => $e->getMessage(),
            ]);

            return UsageRefreshResult::of(UsageRefreshResult::FAILED);
        }

        if ($bytes === null) {
            return UsageRefreshResult::of(UsageRefreshResult::FAILED);
        }

        $account->update([
            'traffic_used_gb' => round($bytes / 1024 ** 3, 2),
            'usage_synced_at' => now(),
        ]);

        return UsageRefreshResult::of(UsageRefreshResult::REFRESHED);
    }

    /**
     * Job دوره‌ای: قدیمی‌ترین هم‌گام‌شده‌ها اول (null اول). سقفِ تعداد هر اجرا تا یک اجرا هرگز
     * پنل‌ها را زیرِ بار نبرد؛ باقی‌مانده در اجرای بعد می‌آید.
     *
     * @return array{attempted: int, refreshed: int, failed: int, unsupported: int}
     */
    public function refreshStale(int $limit = 200, int $olderThanMinutes = 30): array
    {
        $summary = ['attempted' => 0, 'refreshed' => 0, 'failed' => 0, 'unsupported' => 0];

        $accounts = Account::query()
            ->with('serverPanel')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('usage_synced_at')
                ->orWhere('usage_synced_at', '<', now()->subMinutes($olderThanMinutes)))
            ->orderByRaw('usage_synced_at is not null')
            ->orderBy('usage_synced_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($accounts as $account) {
            $summary['attempted']++;

            $status = $this->refresh($account, force: true)->status;

            match ($status) {
                UsageRefreshResult::REFRESHED => $summary['refreshed']++,
                UsageRefreshResult::UNSUPPORTED => $summary['unsupported']++,
                default => $summary['failed']++,
            };
        }

        return $summary;
    }

    protected function throttleKey(Account $account): string
    {
        return 'account-usage-refresh:'.$account->id;
    }
}
