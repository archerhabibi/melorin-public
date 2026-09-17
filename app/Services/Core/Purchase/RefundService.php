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

            if ((float) $order->sold_price > 0) {
                $this->wallet->credit(
                    $customer,
                    (float) $order->sold_price,
                    'refund',
                    $order,
                    "{$reason} — سفارش #{$order->id}",
                    $operation,
                );

                $refunded['customer'] = (float) $order->sold_price;
            }

            // طرف نماینده: از روی خودِ سفارش خوانده می‌شود، نه از روی
            // قیمت فعلی محصول — چون قیمت ممکن است از زمان خرید عوض شده
            // باشد و برگرداندن عدد امروز یعنی برگرداندن مبلغ اشتباه.
            // این دقیقاً همان دلیلی است که اسنپ‌شات قیمت (بند ۱۲) وجود دارد.
            $store = StoreContext::fromReseller($customer->reseller);
            $corePrice = (float) ($order->core_price ?? $order->base_price);

            if ($store->isReseller() && $corePrice > 0) {
                $this->wallet->credit(
                    $store->reseller,
                    $corePrice,
                    'refund',
                    $order,
                    "بازگشت هزینه‌ی پایه — سفارش #{$order->id}",
                    $operation,
                );

                $refunded['reseller'] = $corePrice;
            }

            $order->update([
                'status' => Order::STATUS_REFUNDED,
                'failure_reason' => $reason,
            ]);

            return $refunded;
        });
    }
}
