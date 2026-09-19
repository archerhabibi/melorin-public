<?php

namespace App\Services\Core\Provisioning;

use App\Models\Order;
use App\Models\ProvisioningSetting;
use App\Services\Core\Purchase\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فاز ۱۱ — تنها جایی که «سیاست شکست» اعمال می‌شود (بند ۳۶ و ۳۷ سند).
 *
 * PurchaseService، RenewalService و تلاش‌های مجدد، همه بعد از هر شکست
 * همین کلاس را صدا می‌زنند؛ پس سیاست برای خرید و تمدید یکسان است.
 *
 *   سیاست              | تلاش باقی‌مانده دارد | تلاش تمام شده
 *   -------------------|----------------------|------------------
 *   retry              | retry زمان‌بندی‌شده  | needs_admin
 *   refund             | بازگشت فوری          | (بازگشت فوری)
 *   retry_then_refund  | retry زمان‌بندی‌شده  | بازگشت خودکار
 */
class ProvisioningFailureHandler
{
    public const OUTCOME_REFUNDED = 'refunded';

    public const OUTCOME_RETRY_SCHEDULED = 'retry_scheduled';

    public const OUTCOME_NEEDS_ADMIN = 'needs_admin';

    public function __construct(protected RefundService $refunds) {}

    public function handle(Order $order): string
    {
        $order = $order->fresh();

        if (! $order) {
            return self::OUTCOME_NEEDS_ADMIN;
        }

        if ($order->status === Order::STATUS_REFUNDED) {
            return self::OUTCOME_REFUNDED;
        }

        if ($order->status !== Order::STATUS_PROVISION_FAILED) {
            return self::OUTCOME_NEEDS_ADMIN;
        }

        $policy = ProvisioningSetting::activePolicy();
        $hasAttemptsLeft = (int) $order->provision_attempts < ProvisioningService::MAX_ATTEMPTS;

        $outcome = match (true) {
            $policy === ProvisioningSetting::POLICY_REFUND => $this->autoRefund($order, false),
            $hasAttemptsLeft => $this->scheduleRetry($order),
            $policy === ProvisioningSetting::POLICY_RETRY_THEN_REFUND => $this->autoRefund($order, true),
            default => $this->markNeedsAdmin($order),
        };

        Log::info('provisioning_failure_handled', [
            'order_id' => $order->id,
            'policy' => $policy,
            'attempts' => $order->provision_attempts,
            'outcome' => $outcome,
        ]);

        return $outcome;
    }

    /**
     * بازگشت وجه یک سفارشِ provision_failed. ردیف سفارش قفل می‌شود و
     * وضعیتش دوباره چک می‌شود تا با یک retry هم‌زمان (claimForRetry)
     * «هم اکانت ساخته شود هم پول برگردد» رخ ندهد.
     *
     * @return bool false یعنی وضعیت سفارش دیگر provision_failed نیست
     */
    public function refundFailedOrder(Order $order, string $reason): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== Order::STATUS_PROVISION_FAILED) {
                return false;
            }

            $this->refunds->refundOrder($locked, $reason);

            return true;
        });
    }

    protected function scheduleRetry(Order $order): string
    {
        // تلاش ۱ → ۲ دقیقه، تلاش ۲ → ۴ دقیقه
        $delay = 2 ** max(1, (int) $order->provision_attempts);

        $order->update(['next_provision_retry_at' => now()->addMinutes($delay)]);

        return self::OUTCOME_RETRY_SCHEDULED;
    }

    protected function autoRefund(Order $order, bool $afterRetries): string
    {
        $what = $order->isRenewal() ? 'تمدید' : 'ساخت اکانت';
        $reason = $afterRetries
            ? "بازگشت خودکار وجه پس از {$order->provision_attempts} تلاش ناموفق {$what}"
            : "بازگشت خودکار وجه به‌دلیل شکست {$what}";

        try {
            if ($this->refundFailedOrder($order, $reason)) {
                return self::OUTCOME_REFUNDED;
            }

            return $order->fresh()?->status === Order::STATUS_REFUNDED
                ? self::OUTCOME_REFUNDED
                : self::OUTCOME_NEEDS_ADMIN;
        } catch (\Throwable $e) {
            // بازگشتِ خودکار شکست خورد؛ سفارش همچنان provision_failed
            // است (تراکنش rollback شده) و ادمین باید رسیدگی کند.
            Log::critical('provisioning_auto_refund_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->markNeedsAdmin($order);
        }
    }

    protected function markNeedsAdmin(Order $order): string
    {
        Order::query()->whereKey($order->getKey())->update(['next_provision_retry_at' => null]);

        return self::OUTCOME_NEEDS_ADMIN;
    }
}
