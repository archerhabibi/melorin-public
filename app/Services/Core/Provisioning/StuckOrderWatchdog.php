<?php

namespace App\Services\Core\Provisioning;

use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Services\Core\AuditService;
use Illuminate\Support\Facades\Log;

/**
 * فاز ۹ (G-9-1) — نگهبانِ سفارش‌های گیرکرده در `provisioning`.
 *
 * مشکل: Crash/kill وسط Provisioning سفارش را در `provisioning` رها می‌کند و
 * Retry خودکار فقط `provision_failed` را برمی‌دارد؛ یعنی «پول گرفته شده،
 * سرویس تحویل نشده» و هیچ‌کس خبردار نمی‌شود.
 *
 * رفتار عمدیِ محتاطانه (هم‌راستا با ProvisioningService::canRetry):
 *  - هیچ حرکت مالی انجام نمی‌دهد (نه Refund، نه Debit).
 *  - اگر اکانت برای سفارش ثبت شده باشد، فقط وضعیت را به account_created اصلاح می‌کند.
 *  - در غیر این صورت سفارش را به `provision_failed` می‌برد **بدون** برنامه‌ی Retry
 *    خودکار (next_provision_retry_at = null)؛ چون ممکن است اکانت روی پنل ساخته
 *    شده باشد (G-9-2) و Retry خودکار اکانت یتیم بسازد. تصمیم (Retry اجباری یا
 *    Refund) با ادمین است و دکمه‌هایش برای provision_failed از قبل وجود دارند.
 *  - ظرفیتِ رزروشده آزاد نمی‌شود (اکانت شاید روی پنل باشد).
 *  - هر تغییر با یک UPDATE شرطی انجام می‌شود تا با Provisioning زنده‌ی کند تداخل نکند.
 */
class StuckOrderWatchdog
{
    public const STUCK_MINUTES = 15;

    public function __construct(protected AuditService $audit) {}

    /** @return array{recovered:int, marked_failed:int} */
    public function run(int $limit = 50, ?int $minutes = null): array
    {
        $cutoff = now()->subMinutes(max(1, $minutes ?? self::STUCK_MINUTES));
        $summary = ['recovered' => 0, 'marked_failed' => 0];

        $orders = Order::query()
            ->where('status', Order::STATUS_PROVISIONING)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($orders as $order) {
            $hasAccount = $order->account()->exists();

            if ($hasAccount && ! $order->isRenewal()) {
                $changed = $this->transition($order, $cutoff, Order::STATUS_ACCOUNT_CREATED, null);
                $changed && $summary['recovered']++;
                $changed && $this->audit->record('provisioning.watchdog_recovered', $order, ['status' => Order::STATUS_PROVISIONING], ['status' => Order::STATUS_ACCOUNT_CREATED]);

                continue;
            }

            $reason = 'سفارش بیش از '.($minutes ?? self::STUCK_MINUTES).' دقیقه در حال ساخت مانده بود (Crash یا قطع شدن Worker). '
                .'قبل از Retry اجباری، وجود اکانت با note=melorin-order-'.$order->id.' را روی پنل بررسی کنید.';

            if ($this->transition($order, $cutoff, Order::STATUS_PROVISION_FAILED, $reason)) {
                $summary['marked_failed']++;

                $order->provisioningAttempts()
                    ->where('status', ProvisioningAttempt::STATUS_STARTED)
                    ->update([
                        'status' => ProvisioningAttempt::STATUS_FAILED,
                        'error' => $reason,
                        'finished_at' => now(),
                    ]);

                Log::error('provisioning_stuck_marked_failed', ['order_id' => $order->id]);
                $this->audit->record('provisioning.watchdog_marked_failed', $order, ['status' => Order::STATUS_PROVISIONING], ['status' => Order::STATUS_PROVISION_FAILED]);
            }
        }

        return $summary;
    }

    /** UPDATE شرطی: فقط اگر هنوز provisioning و همچنان قدیمی باشد. */
    protected function transition(Order $order, $cutoff, string $to, ?string $reason): bool
    {
        $data = ['status' => $to, 'next_provision_retry_at' => null, 'updated_at' => now()];
        $data['failure_reason'] = $reason;

        return Order::query()
            ->whereKey($order->getKey())
            ->where('status', Order::STATUS_PROVISIONING)
            ->where('updated_at', '<', $cutoff)
            ->update($data) === 1;
    }
}
