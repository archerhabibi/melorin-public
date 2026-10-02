<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Support\Money;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\Support\Keyboards;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Telegram\Bot\Api;

/**
 * جریان «💰 کیف پول و شارژ حساب» (بند ۱۱، ۱۲ سند). خرید مستقیماً از این
 * هندلر انجام نمی‌شود — این هندلر فقط شارژ کیف پول را مدیریت می‌کند؛
 * تنها PaymentService و WalletService حق تغییر رکوردها را دارند.
 */
class WalletHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected WalletService $walletService,
        protected PaymentService $paymentService,
        protected IdentityService $identity,
    ) {}

    public function showBalance(int $chatId, User $user): void
    {
        // بریج backward-compatible (همان توضیح PaymentService::resolveWalletOwner):
        // موجودیِ واقعیِ این ربات، کیف‌پولِ CustomerAccountِ فروشگاه اصلی
        // این کاربر است، نه کیف‌پولی که مستقیماً روی خودِ User باشد —
        // چون خرید/تمدید هم دقیقاً از همان‌جا کسر می‌کنند. قبلاً اینجا
        // $this->walletService->balance($user) صدا زده می‌شد که یک
        // کیف‌پول کاملاً جدا (و همیشه صفر) نشان می‌داد.
        $balance = $this->walletService->balance(
            $this->identity->resolveCustomerAccount($user, StoreContext::main())
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی کیف پول شما: '.Money::format($balance)."\n\nبرای شارژ، مبلغ را انتخاب کنید:",
            'reply_markup' => Keyboards::walletTopupAmounts(),
        ]);
    }

    public function chooseAmount(int $chatId, User $user, string $amount): void
    {
        if ($amount === 'custom') {
            $this->state->set($chatId, ConversationState::WALLET_AWAITING_AMOUNT, [], $user);
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ دلخواه را به '.Money::label().' وارد کنید (فقط عدد):']);

            return;
        }

        $this->promptPaymentMethod($chatId, $user, (int) $amount);
    }

    public function handleCustomAmountText(int $chatId, User $user, string $text): void
    {
        $amount = Money::parseOrZero($text);

        if ($amount < Money::minTopup('customer')) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'مبلغ نامعتبر است. حداقل مبلغ شارژ '.Money::format(Money::minTopup()).' است.']);

            return;
        }

        $this->promptPaymentMethod($chatId, $user, $amount);
    }

    protected function promptPaymentMethod(int $chatId, User $user, int $amount): void
    {
        $methods = PaymentMethod::query()->where('status', 'active')->get();

        // باگ واقعی: وقتی هیچ روش پرداخت فعالی در پنل مدیریت تعریف نشده
        // بود، Keyboards::paymentMethods($methods) یک این‌لاین‌کیبورد
        // خالی می‌ساخت. وقتی PHP یک آرایه‌ی خالی را json_encode می‌کند،
        // به‌جای یک object خالی ({}) به یک آرایه‌ی خالی ([]) تبدیل می‌شود؛
        // تلگرام دقیقاً با همین خطا آن را رد می‌کند:
        // «Bad Request: object expected as reply markup» — و کل پیام
        // (و در نتیجه‌ی آن، کل فرآیند شارژ کیف پول) با خطای ۵۰۰ شکست
        // می‌خورد، بدون این‌که کاربر هیچ پیامی ببیند.
        if ($methods->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'در حال حاضر هیچ روش پرداخت فعالی تنظیم نشده است. لطفاً موضوع را به پشتیبانی اطلاع دهید.',
            ]);

            return;
        }

        $this->state->set($chatId, ConversationState::WALLET_CHOOSE_METHOD, ['amount' => $amount], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'روش پرداخت را انتخاب کنید:',
            'reply_markup' => Keyboards::paymentMethods($methods),
        ]);
    }

    public function chooseMethod(int $chatId, User $user, int $methodId): void
    {
        $state = $this->state->find($chatId);
        $amount = (int) ($state->payload['amount'] ?? 0);
        $amountText = Money::format($amount);
        $method = PaymentMethod::query()->where('status', 'active')->findOrFail($methodId);

        ['payment' => $payment, 'initiation' => $initiation] = $this->paymentService->initiate(
            $user, $method, $amount, 'wallet_charge'
        );

        if ($method->type === 'card_to_card') {
            $this->state->set($chatId, ConversationState::WALLET_AWAITING_RECEIPT, ['payment_id' => $payment->id], $user);

            $card = $initiation->instructions['card_number'] ?? '—';
            $holder = $initiation->instructions['card_holder_name'] ?? '—';

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "مبلغ {$amountText} را به کارت زیر واریز کرده و سپس عکس رسید را همین‌جا ارسال کنید:\n\nشماره کارت: {$card}\nبه نام: {$holder}",
            ]);

            return;
        }

        $this->state->reset($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "برای پرداخت آنلاین روی لینک زیر بروید:\n".($initiation->redirectUrl ?? 'لینک پرداخت در دسترس نیست، لطفاً بعداً تلاش کنید.'),
        ]);
    }

    public function handleReceiptPhoto(int $chatId, User $user, string $fileId): void
    {
        $state = $this->state->find($chatId);
        $paymentId = $state->payload['payment_id'] ?? null;

        if (! $paymentId) {
            return;
        }

        $payment = Payment::findPendingForReceipt($paymentId, $user, null, 'user');

        if (! $payment) {
            return;
        }

        // fileId تلگرام همین‌جا به‌عنوان مرجع رسید ذخیره می‌شود؛ دانلود و
        // ذخیره‌ی فایل واقعی روی storage در پیاده‌سازی نهایی باید از طریق
        // Api::getFile() انجام شود.
        $payment->update(['receipt_image' => $fileId]);

        // قبل از بستن جریان، اسم صاحب کارتِ واریزکننده را می‌پرسیم — چون
        // ممکن است با نام کاربر تلگرام یکی نباشد و ادمین برای تطبیق با
        // صورتحساب بانکی به آن نیاز دارد.
        $this->state->set($chatId, ConversationState::WALLET_AWAITING_DEPOSITOR_NAME, ['payment_id' => $paymentId], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "رسید دریافت شد ✅\n\nحالا نام و نام خانوادگیِ صاحب کارتی که از آن واریز کردید را وارد کنید (همان اسمی که روی کارت بانکی چاپ شده):",
        ]);
    }

    public function handleDepositorName(int $chatId, User $user, string $text): void
    {
        $state = $this->state->find($chatId);
        $paymentId = $state->payload['payment_id'] ?? null;

        if (! $paymentId) {
            $this->state->reset($chatId);

            return;
        }

        $depositorName = trim($text);

        if (mb_strlen($depositorName) < 2) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'لطفاً نام معتبر وارد کنید.']);

            return;
        }

        Payment::findPendingForReceipt($paymentId, $user, null, 'user')
            ?->update(['depositor_name' => $depositorName]);

        $this->state->reset($chatId);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'ثبت شد. پس از تأیید ادمین، کیف پول شما شارژ خواهد شد. ✅',
        ]);
    }
}
