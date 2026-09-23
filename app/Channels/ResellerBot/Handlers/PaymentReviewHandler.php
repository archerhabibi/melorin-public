<?php

namespace App\Channels\ResellerBot\Handlers;

use App\Exceptions\ResellerScopeViolationException;
use App\Services\Core\Payments\InvalidPaymentTransitionException;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Resellers\ResellerService;
use Telegram\Bot\Api;

/**
 * دکمه‌های ✅/❌ زیر رسیدی که WalletHandler برای owner فرستاده. طبق
 * تصمیم صریح، فقط owner همین Reseller مجاز به این کار است —
 * PaymentService::confirmManualByReseller خودش این مرز را enforce
 * می‌کند (نه این‌جا با UI)، این هندلر فقط پیام را ویرایش می‌کند.
 */
class PaymentReviewHandler
{
    public function __construct(
        protected Api $telegram,
        protected PaymentService $paymentService,
        protected ResellerService $resellerService,
    ) {}

    public function approve(Reseller $reseller, int $chatId, User $actor, int $paymentId): void
    {
        if (! $this->resellerService->isOwner($reseller, $actor)) {
            return;
        }

        $payment = Payment::query()->find($paymentId);

        if (! $payment) {
            return;
        }

        try {
            $this->paymentService->confirmManualByReseller($payment, $reseller);
        } catch (ResellerScopeViolationException|InvalidPaymentTransitionException|\LogicException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "تایید ناموفق: {$e->getMessage()}"]);

            return;
        }

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '✅ پرداخت تایید و کیف‌پول مشتری شارژ شد.']);

        if ($payment->user?->telegram_id) {
            $this->telegram->sendMessage([
                'chat_id' => $payment->user->telegram_id,
                'text' => '✅ شارژ کیف پول شما به مبلغ '.number_format((float) $payment->amount).' تومان تایید شد.',
            ]);
        }
    }

    public function reject(Reseller $reseller, int $chatId, User $actor, int $paymentId): void
    {
        if (! $this->resellerService->isOwner($reseller, $actor)) {
            return;
        }

        $payment = Payment::query()->find($paymentId);

        if (! $payment) {
            return;
        }

        try {
            $this->paymentService->rejectByReseller($payment, $reseller);
        } catch (ResellerScopeViolationException|InvalidPaymentTransitionException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "رد ناموفق: {$e->getMessage()}"]);

            return;
        }

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '❌ پرداخت رد شد.']);

        if ($payment->user?->telegram_id) {
            $this->telegram->sendMessage([
                'chat_id' => $payment->user->telegram_id,
                'text' => '❌ درخواست شارژ کیف پول شما رد شد. برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.',
            ]);
        }
    }
}
