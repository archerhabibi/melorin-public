<?php

namespace App\Services\Core\Purchase;

use App\Models\Operation;
use App\Models\Order;
use App\Services\Core\OperationService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * فاز B — بازگشت وجه یک سفارش (بند ۵۶، Phase B: «Refund»).
 *
 * نکته‌ی اصلی: در فروش نمایندگی دو کسر انجام شده بود، پس بازگشت هم
 * باید دو طرفه باشد — مشتری قیمت فروش را پس می‌گیرد و نماینده قیمت
 * عمده را. برگرداندن فقط یک طرف یعنی یکی از دو نفر ضرر می‌کند.
 *
 * idempotent است: سفارشِ از قبل بازگشت‌خورده دوباره بازگشت نمی‌خورد.
 * بدون این محافظت، دو کلیک ادمین یعنی دو بار شارژ کیف‌پول.
 */
class RefundService
{
    public function __construct(
        protected WalletService $wallet,
        protected OperationService $operations,
    ) {}

    public function refundOrder(Order $order, string $reason = 'بازگشت وجه سفارش'): Order
    {
        $this->operations->runOnce(
            "refund:order:{$order->id}",
            Operation::TYPE_REFUND,
            fn (Operation $operation) => $this->execute($order, $reason, $operation),
            payload: ['order_id' => $order->id],
        );

        return $order->fresh();
    }

    protected function execute(Order $order, string $reason, Operation $operation): array
    {
        if ($order->status === Order::STATUS_REFUNDED) {
            throw new PurchaseNotAllowedException("سفارش #{$order->id} قبلاً بازگشت خورده است.");
        }

        if (! $order->isFinanciallySettled()) {
            throw new PurchaseNotAllowedException(
                "سفارش #{$order->id} تسویه‌ی مالی نشده؛ چیزی برای بازگشت وجود ندارد."
            );
        }

        $customer = $order->customerAccount;

        if (! $customer) {
            throw new PurchaseNotAllowedException("مالک سفارش #{$order->id} مشخص نیست.");
        }

        return DB::transaction(function () use ($order, $customer, $reason, $operation) {
            $refunded = [];

            // طرف مشتری: دقیقاً همان چیزی که پرداخته — main_price در
            // فروشگاه اصلی، Customers_price در فروشگاه نماینده.
            $customerPaid = (float) ($order->customers_price ?? $order->main_price ?? 0);

            if ($customerPaid > 0) {
                $this->wallet->credit(
                    $customer,
                    $customerPaid,
                    'refund',
                    $order,
                    "{$reason} — سفارش #{$order->id}",
                    $operation,
                );

                $refunded['customer'] = $customerPaid;
            }

            // طرف نماینده: از روی خودِ سفارش خوانده می‌شود، نه از روی
            // قیمت فعلی محصول — چون قیمت ممکن است از زمان خرید عوض شده
            // باشد و برگرداندن عدد امروز یعنی برگرداندن مبلغ اشتباه.
            // این دقیقاً همان دلیلی است که اسنپ‌شات قیمت (بند ۱۲) وجود دارد.
            $store = StoreContext::fromReseller($customer->reseller);
            $resellerPaid = (float) ($order->reseller_price ?? 0);

            if ($store->isReseller() && $resellerPaid > 0) {
                $this->wallet->credit(
                    $store->reseller,
                    $resellerPaid,
                    'refund',
                    $order,
                    "بازگشت reseller_price — سفارش #{$order->id}",
                    $operation,
                );

                $refunded['reseller'] = $resellerPaid;
            }

            $order->update([
                'status' => Order::STATUS_REFUNDED,
                'failure_reason' => $reason,
                'next_provision_retry_at' => null,
            ]);

            return $refunded;
        });
    }
}
