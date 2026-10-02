<?php

namespace App\Services\Core\Payments;

use App\DataTransferObjects\GatewayInitiationResult;
use App\DataTransferObjects\GatewayVerificationResult;
use App\Models\Payment;

/**
 * درگاه کارت‌به‌کارت (بند ۱۲): کاربر مبلغ را به شماره کارت اعلام‌شده واریز
 * می‌کند، تصویر رسید را آپلود می‌کند (در ستون payments.receipt_image ذخیره
 * می‌شود) و پرداخت در وضعیت pending می‌ماند تا ادمین آن را از پنل بررسی و
 * با PaymentService::confirmManual() تایید یا رد کند.
 *
 * اطلاعات کارت از تنظیمات همان روش پرداخت (payment_methods.settings)
 * خوانده می‌شود، مثلاً:
 * {"card_number": "6037-....", "card_holder_name": "...", "bank_name": "..."}
 */
class CardToCardGateway implements PaymentGatewayInterface
{
    public function isManual(): bool
    {
        return true;
    }

    public function initiate(Payment $payment): GatewayInitiationResult
    {
        $settings = $payment->paymentMethod->settings ?? [];

        return GatewayInitiationResult::manual([
            'card_number' => $settings['card_number'] ?? null,
            'card_holder_name' => $settings['card_holder_name'] ?? null,
            'bank_name' => $settings['bank_name'] ?? null,
            'amount' => (int) $payment->amount,
        ]);
    }

    public function verify(Payment $payment, array $callbackData): GatewayVerificationResult
    {
        // کارت‌به‌کارت callback خودکار ندارد؛ تایید همیشه دستی و توسط ادمین
        // از طریق PaymentService::confirmManual() انجام می‌شود.
        return GatewayVerificationResult::fail(
            'درگاه کارت‌به‌کارت به‌صورت خودکار تایید نمی‌شود؛ باید توسط ادمین بررسی شود.'
        );
    }
}
