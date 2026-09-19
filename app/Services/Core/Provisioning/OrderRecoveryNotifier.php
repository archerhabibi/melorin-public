<?php

namespace App\Services\Core\Provisioning;

use App\Channels\ResellerBot\ResellerApiFactory;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * وقتی نتیجه‌ی یک سفارش «بعداً و در پس‌زمینه» مشخص می‌شود (retry موفق،
 * بازگشت وجه خودکار یا دستی)، مشتری باید بفهمد. بدون این، مشتری که به او
 * گفته‌ایم «دوباره تلاش می‌کنیم» هیچ‌وقت نتیجه را نمی‌فهمد.
 *
 * شکست ارسال فقط لاگ می‌شود و هرگز عملیات مالی را خراب نمی‌کند.
 */
class OrderRecoveryNotifier
{
    public function __construct(protected ResellerApiFactory $resellerApis) {}

    public function delivered(Order $order): void
    {
        $order = $order->fresh(['renewedAccount', 'account']);
        $account = $order?->isRenewal() ? $order->renewedAccount : $order?->account;

        $text = $order?->isRenewal()
            ? "✅ تمدید اکانت شما انجام شد.\n\nنام کاربری: {$account?->panel_username}"
            : "✅ سرویس شما آماده شد.\n\nنام کاربری: {$account?->panel_username}\nبرای دریافت لینک اتصال به بخش «اکانت‌های من» مراجعه کنید.";

        $this->send($order, $text);
    }

    public function refunded(Order $order): void
    {
        $what = $order->isRenewal() ? 'تمدید' : 'ساخت سرویس';

        $this->send($order, "↩️ متأسفانه {$what} انجام نشد و مبلغ پرداختی به کیف پول شما بازگردانده شد.");
    }

    protected function send(?Order $order, string $text): void
    {
        try {
            $customer = $order?->customerAccount;
            $chatId = $customer?->user?->telegram_id;

            if (! $chatId) {
                return;
            }

            $api = $customer->reseller_id
                ? $this->resellerApis->make($customer->reseller)
                : app(Api::class);

            $api->sendMessage(['chat_id' => $chatId, 'text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('order_recovery_notification_failed', [
                'order_id' => $order?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
