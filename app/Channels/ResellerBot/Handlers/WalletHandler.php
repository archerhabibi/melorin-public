<?php

namespace App\Channels\ResellerBot\Handlers;

use App\Channels\ResellerBot\Support\ConversationState;
use App\Channels\ResellerBot\Support\Keyboards;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use Telegram\Bot\Api;

/**
 * «💰 شارژ حساب» — کیف‌پول شخصیِ خودِ مشتری، طبق تصمیم صریح: چون
 * نماینده پول واریزی مشتری‌اش را مستقیم دریافت می‌کند، تاییدِ رسید هم با
 * خودِ نماینده است، نه ادمین اصلی (ر.ک. PaymentService::confirmManualByReseller).
 * چون هنوز پنل وب نماینده وجود ندارد، تاییدیه از طریق همین ربات و دو
 * دکمه‌ی این‌لاین به owner نماینده فرستاده می‌شود.
 */
class WalletHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected WalletService $walletService,
        protected PaymentService $paymentService,
    ) {}

    public function showBalance(Reseller $reseller, int $chatId, User $user): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی کیف پول شما: '.number_format($this->walletService->balance($user))."تومان\n\nبرای شارژ، مبلغ را انتخاب کنید:",
            'reply_markup' => Keyboards::walletTopupAmounts(),
        ]);
    }

    public function chooseAmount(Reseller $reseller, int $chatId, User $user, string $amount): void
    {
        if ($amount === 'custom') {
            $this->state->set($reseller, $chatId, ConversationState::WALLET_AWAITING_AMOUNT, [], $user);
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ دلخواه را به تومان وارد کنید (فقط عدد):']);

            return;
        }

        $this->promptPaymentMethod($reseller, $chatId, $user, (float) $amount);
    }

    public function handleCustomAmountText(Reseller $reseller, int $chatId, User $user, string $text): void
    {
        $amount = (float) preg_replace('/[^0-9.]/', '', $text);

        if ($amount < 10000) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ نامعتبر است. حداقل مبلغ شارژ ۱۰,۰۰۰ تومان است.']);

            return;
        }

        $this->promptPaymentMethod($reseller, $chatId, $user, $amount);
    }

    protected function promptPaymentMethod(Reseller $reseller, int $chatId, User $user, float $amount): void
    {
        $methods = PaymentMethod::query()->where('status', 'active')->get();

        if ($methods->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'در حال حاضر هیچ روش پرداخت فعالی تنظیم نشده است.']);

            return;
        }

        $this->state->set($reseller, $chatId, ConversationState::WALLET_CHOOSE_METHOD, ['amount' => $amount], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'روش پرداخت را انتخاب کنید:',
            'reply_markup' => Keyboards::paymentMethods($methods, 'rwallet'),
        ]);
    }

    public function chooseMethod(Reseller $reseller, int $chatId, User $user, int $methodId): void
    {
        $state = $this->state->find($reseller, $chatId);
        $amount = (float) ($state->payload['amount'] ?? 0);
        $method = PaymentMethod::query()->where('status', 'active')->findOrFail($methodId);

        ['payment' => $payment, 'initiation' => $initiation] = $this->paymentService->initiate(
            $user, $method, $amount, 'wallet_charge', reseller: $reseller, walletOwnerType: 'user'
        );

        if ($method->type === 'card_to_card') {
            $this->state->set($reseller, $chatId, ConversationState::WALLET_AWAITING_RECEIPT, ['payment_id' => $payment->id], $user);

            $card = $initiation->instructions['card_number'] ?? '—';
            $holder = $initiation->instructions['card_holder_name'] ?? '—';

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "مبلغ {$amount} تومان را به کارت زیر واریز کرده و سپس عکس رسید را همین‌جا ارسال کنید:\n\nشماره کارت: {$card}\nبه نام: {$holder}",
            ]);

            return;
        }

        $this->state->reset($reseller, $chatId);
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'این روش پرداخت در حال حاضر پشتیبانی نمی‌شود؛ لطفاً کارت‌به‌کارت را انتخاب کنید.']);
    }

    public function handleReceiptPhoto(Reseller $reseller, int $chatId, User $user, string $fileId): void
    {
        $state = $this->state->find($reseller, $chatId);
        $paymentId = $state->payload['payment_id'] ?? null;

        if (! $paymentId) {
            return;
        }

        Payment::query()->whereKey($paymentId)->update(['receipt_image' => $fileId]);

        $this->state->set($reseller, $chatId, ConversationState::WALLET_AWAITING_DEPOSITOR_NAME, ['payment_id' => $paymentId], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'رسید دریافت شد ✅'."\n\nحالا نام و نام خانوادگیِ صاحب کارتی که از آن واریز کردید را وارد کنید:",
        ]);
    }

    public function handleDepositorName(Reseller $reseller, int $chatId, User $user, string $text): void
    {
        $state = $this->state->find($reseller, $chatId);
        $paymentId = $state->payload['payment_id'] ?? null;

        if (! $paymentId) {
            $this->state->reset($reseller, $chatId);

            return;
        }

        $depositorName = trim($text);

        if (mb_strlen($depositorName) < 2) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'لطفاً نام معتبر وارد کنید.']);

            return;
        }

        $payment = Payment::query()->whereKey($paymentId)->first();
        $payment?->update(['depositor_name' => $depositorName]);

        $this->state->reset($reseller, $chatId);

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'ثبت شد. پس از تأیید نماینده، کیف پول شما شارژ خواهد شد. ✅']);

        if ($payment && $reseller->user?->telegram_id) {
            $this->telegram->sendMessage([
                'chat_id' => $reseller->user->telegram_id,
                'text' => "🧾 درخواست شارژ کیف‌پول جدید\n\n"
                    ."از: {$user->full_name} (شناسه: {$user->telegram_id})\n"
                    .'مبلغ: '.number_format((float) $payment->amount)." تومان\n"
                    ."واریزکننده: {$depositorName}",
            ]);

            $this->telegram->sendPhoto([
                'chat_id' => $reseller->user->telegram_id,
                'photo' => $payment->receipt_image,
                'caption' => 'رسید بالا 👆',
                'reply_markup' => Keyboards::paymentReview($payment->id),
            ]);
        }
    }
}
