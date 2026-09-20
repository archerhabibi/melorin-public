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
 * «💰 شارژ حساب نماینده» — شارژ Wallet صاحبِ نماینده در Main Context
 * (همان Walletی که reseller_price از آن کسر می‌شود؛ Rule 6 سند). طبق تصمیم صریح، برخلاف شارژ کیف‌پول مشتری،
 * این یکی هم‌چنان توسط ادمین اصلی تایید می‌شود (چون پول واقعاً به خودِ
 * پلتفرم می‌رسد) — از طریق پنل Filament موجود، نه این ربات؛ اینجا فقط
 * پرداخت pending ساخته می‌شود، دقیقاً مثل جریان شارژ کیف‌پول ربات اصلی.
 * فقط owner به این بخش دسترسی دارد (ر.ک. UpdateRouter).
 */
class ResellerWalletHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected WalletService $walletService,
        protected PaymentService $paymentService,
    ) {}

    public function showBalance(Reseller $reseller, int $chatId): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی اعتبار نماینده: '.number_format($this->walletService->balance($reseller))."تومان\n\nبرای شارژ، مبلغ را انتخاب کنید:",
            'reply_markup' => Keyboards::resellerWalletTopupAmounts(),
        ]);
    }

    public function chooseAmount(Reseller $reseller, int $chatId, User $user, string $amount): void
    {
        if ($amount === 'custom') {
            $this->state->set($reseller, $chatId, ConversationState::OWNER_MAIN_WALLET_TOPUP_AWAITING_AMOUNT, [], $user);
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ دلخواه را به تومان وارد کنید (فقط عدد):']);

            return;
        }

        $this->promptPaymentMethod($reseller, $chatId, $user, (float) $amount);
    }

    public function handleCustomAmountText(Reseller $reseller, int $chatId, User $user, string $text): void
    {
        $amount = (float) preg_replace('/[^0-9.]/', '', $text);

        if ($amount < 100000) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ نامعتبر است. حداقل مبلغ شارژ اعتبار نماینده ۱۰۰,۰۰۰ تومان است.']);

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

        $this->state->set($reseller, $chatId, ConversationState::OWNER_MAIN_WALLET_TOPUP_CHOOSE_METHOD, ['amount' => $amount], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'روش پرداخت را انتخاب کنید:',
            'reply_markup' => Keyboards::paymentMethods($methods, 'rswallet'),
        ]);
    }

    public function chooseMethod(Reseller $reseller, int $chatId, User $user, int $methodId): void
    {
        $state = $this->state->find($reseller, $chatId);
        $amount = (float) ($state->payload['amount'] ?? 0);
        $method = PaymentMethod::query()->where('status', 'active')->findOrFail($methodId);

        ['payment' => $payment, 'initiation' => $initiation] = $this->paymentService->initiate(
            $user, $method, $amount, 'wallet_charge', reseller: $reseller, walletOwnerType: 'reseller'
        );

        if ($method->type === 'card_to_card') {
            $this->state->set($reseller, $chatId, ConversationState::OWNER_MAIN_WALLET_TOPUP_AWAITING_RECEIPT, ['payment_id' => $payment->id], $user);

            $card = $initiation->instructions['card_number'] ?? '—';
            $holder = $initiation->instructions['card_holder_name'] ?? '—';

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "مبلغ {$amount} تومان را به کارت زیر (متعلق به پلتفرم) واریز کرده و سپس عکس رسید را همین‌جا ارسال کنید:\n\nشماره کارت: {$card}\nبه نام: {$holder}",
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

        $payment = Payment::findPendingForReceipt($paymentId, $user, $reseller, 'reseller');

        if (! $payment) {
            return;
        }

        $payment->update(['receipt_image' => $fileId]);
        $this->state->set($reseller, $chatId, ConversationState::OWNER_MAIN_WALLET_TOPUP_AWAITING_DEPOSITOR_NAME, ['payment_id' => $paymentId], $user);

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

        Payment::findPendingForReceipt($paymentId, $user, $reseller, 'reseller')
            ?->update(['depositor_name' => $depositorName]);
        $this->state->reset($reseller, $chatId);

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'ثبت شد. پس از تأیید ادمین اصلی، اعتبار شما شارژ خواهد شد. ✅']);
    }
}
