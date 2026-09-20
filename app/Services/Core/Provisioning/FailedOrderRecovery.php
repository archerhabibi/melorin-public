<?php

namespace App\Services\Core\Provisioning;

use App\Models\Order;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Renewal\RenewalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * فاز ۱۱ — نقطه‌ی واحد جبران سفارش‌های provision_failed، برای هر سه مصرف‌کننده:
 * Command زمان‌بندی‌شده، دکمه‌های پنل ادمین، و هر کانال آینده.
 *
 * هیچ‌کدام از مسیرها کسر مالی جدید ندارند (بند ۳۹).
 */
class FailedOrderRecovery
{
    public function __construct(
        protected PurchaseService $purchases,
        protected RenewalService $renewals,
        protected ProvisioningFailureHandler $failures,
        protected OrderRecoveryNotifier $notifier,
    ) {}

    /**
     * $force=true یعنی سقف ۳ تلاش نادیده گرفته شود (تصمیم آگاهانه‌ی ادمین).
     */
    public function retry(Order $order, bool $force = false): RecoveryResult
    {

        if (! $force && (int) $order->provision_attempts >= ProvisioningService::MAX_ATTEMPTS) {
            return new RecoveryResult(
                RecoveryResult::SKIPPED,
                "سفارش #{$order->id} به سقف تلاش مجاز رسیده و بدون force قابل تلاش مجدد نیست."
            );
        }

        try {
            $order->isRenewal()
                ? $this->renewals->retry($order, $force)
                : $this->purchases->retryProvisioning($order, $force);
        } catch (ProvisioningFailedException|RenewalFailedException $e) {
            if ($e->outcome() === ProvisioningFailureHandler::OUTCOME_REFUNDED) {
                $this->notifier->refunded($order);
            }

            return new RecoveryResult(RecoveryResult::FAILED, $e->getMessage(), $e->outcome());
        } catch (PurchaseNotAllowedException $e) {
            return new RecoveryResult(RecoveryResult::SKIPPED, $e->getMessage());
        }

        $this->notifier->delivered($order);

        return new RecoveryResult(RecoveryResult::SUCCEEDED, 'سرویس با موفقیت تحویل شد؛ کسر مالی دوباره انجام نشد.');
    }

    /** بازگشت دستی (ادمین) — فقط برای سفارش provision_failed. */
    public function refund(Order $order, ?string $reason = null): RecoveryResult
    {
        try {
            $done = $this->failures->refundFailedOrder(
                $order,
                $reason ?? 'بازگشت وجه توسط ادمین به‌دلیل شکست ساخت/تمدید'
            );
        } catch (\Throwable $e) {
            return new RecoveryResult(RecoveryResult::FAILED, $e->getMessage());
        }

        if (! $done) {
            return new RecoveryResult(RecoveryResult::SKIPPED, 'وضعیت این سفارش دیگر «ساخت ناموفق» نیست؛ بازگشتی انجام نشد.');
        }

        $this->notifier->refunded($order);

        return new RecoveryResult(
            RecoveryResult::SUCCEEDED,
            'وجه بازگردانده شد.',
            ProvisioningFailureHandler::OUTCOME_REFUNDED,
        );
    }

    /** سفارش‌هایی که زمان retry خودکارشان رسیده. */
    public function dueOrders(int $limit = 25): Collection
    {
        return Order::query()
            ->where('status', Order::STATUS_PROVISION_FAILED)
            ->where('provision_attempts', '<=', ProvisioningService::MAX_ATTEMPTS)
            ->whereNotNull('next_provision_retry_at')
            ->where('next_provision_retry_at', '<=', now())
            ->orderBy('next_provision_retry_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /** @return array{attempted:int, succeeded:int, failed:int, skipped:int, refunded:int} */
    public function runDueRetries(int $limit = 25): array
    {
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'refunded' => 0];

        foreach ($this->dueOrders($limit) as $order) {
            $summary['attempted']++;

            try {
                $result = $this->retry($order);
            } catch (\Throwable $e) {
                Log::error('auto_retry_crashed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                $summary['failed']++;

                continue;
            }

            if ($result->succeeded()) {
                $summary['succeeded']++;
            } elseif ($result->status === RecoveryResult::SKIPPED) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
                if ($result->outcome === ProvisioningFailureHandler::OUTCOME_REFUNDED) {
                    $summary['refunded']++;
                }
            }
        }

        return $summary;
    }
}
