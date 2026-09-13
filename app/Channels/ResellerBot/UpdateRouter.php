<?php

namespace App\Channels\ResellerBot;

use App\Channels\ResellerBot\Handlers\AccountsHandler;
use App\Channels\ResellerBot\Handlers\BuyAccountHandler;
use App\Channels\ResellerBot\Handlers\PaymentReviewHandler;
use App\Channels\ResellerBot\Handlers\ResellerWalletHandler;
use App\Channels\ResellerBot\Handlers\StartHandler;
use App\Channels\ResellerBot\Handlers\WalletHandler;
use App\Channels\ResellerBot\Support\ConversationState;
use App\Models\Reseller;
use App\Models\ResellerBotSetting;
use App\Models\User;
use App\Services\Resellers\ResellerService;
use Illuminate\Support\Facades\URL;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/**
 * هم‌الگو با App\Channels\TelegramBot\UpdateRouter، با یک تفاوت
 * ساختاری: همه‌چیز نسبت به یک $reseller مشخص (که WebhookController از
 * روی bot_token در URL پیدا کرده) عمل می‌کند — هیچ منطقی اینجا نباید
 * بدون این پارامتر روی داده‌ای کوئری بزند (بند ۱۱ سند معماری: «هیچ
 * Query مربوط به Reseller بدون Scope مجاز نیست»).
 */
class UpdateRouter
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected StartHandler $start,
        protected BuyAccountHandler $buy,
        protected WalletHandler $wallet,
        protected ResellerWalletHandler $resellerWallet,
        protected AccountsHandler $accounts,
        protected PaymentReviewHandler $paymentReview,
        protected ResellerService $resellerService,
    ) {}

    public function handle(Reseller $reseller, Update $update, User $user, int $chatId): void
    {
        $callbackQuery = $update->get('callback_query');

        if ($callbackQuery) {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQuery->get('id')]);
            $this->routeCallback($reseller, $chatId, $user, (string) $callbackQuery->get('data'));

            return;
        }

        $message = $update->get('message');

        if (! $message) {
            return;
        }

        $photos = $message->get('photo');

        if ($photos) {
            $largest = collect($photos)->last();
            $step = $this->state->find($reseller, $chatId)->step;

            match ($step) {
                ConversationState::WALLET_AWAITING_RECEIPT => $this->wallet->handleReceiptPhoto($reseller, $chatId, $user, $largest['file_id']),
                ConversationState::RESELLER_WALLET_AWAITING_RECEIPT => $this->resellerWallet->handleReceiptPhoto($reseller, $chatId, $user, $largest['file_id']),
                default => null,
            };

            return;
        }

        $text = trim((string) $message->getText());

        if (str_starts_with($text, '/start')) {
            $payload = trim(substr($text, strlen('/start'))) ?: null;
            $this->start->handle($reseller, $chatId, $user, $payload);

            return;
        }

        if (in_array($text, ['ادمین', '/admin'], true)) {
            $this->handleAdminCommand($reseller, $chatId, $user);

            return;
        }

        // طبق بند ۲۴ سند Spec: «در Disabled: No new sales, No sensitive
        // operation» — این چک قبل از هر منطق فروش/مالی دیگر انجام
        // می‌شود، نه فقط پنهان‌کردن دکمه در UI.
        if (! ResellerBotSetting::forReseller($reseller)->bot_enabled) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'این فروشگاه موقتاً غیرفعال است.']);

            return;
        }

        $mainMenuRoute = $this->routeMainMenuText($reseller, $chatId, $user, $text);
        if ($mainMenuRoute) {
            return;
        }

        $this->routeFreeText($reseller, $chatId, $user, $text);
    }

    protected function routeMainMenuText(Reseller $reseller, int $chatId, User $user, string $text): bool
    {
        $isOwner = $this->resellerService->isOwner($reseller, $user);

        match ($text) {
            '🛒 خرید اکانت' => $this->buy->start($reseller, $chatId, $user),
            '📦 اکانت‌های من' => $this->accounts->list($reseller, $chatId, $user),
            '💰 شارژ حساب' => $this->wallet->showBalance($reseller, $chatId, $user),
            '🎁 دعوت از دوستان' => $this->start->referral($reseller, $chatId, $user),
            '📚 قوانین و آموزش' => $this->start->rules($reseller, $chatId),
            '🎧 پشتیبانی' => $this->start->support($reseller, $chatId),
            '💰 شارژ حساب نماینده' => $isOwner ? $this->resellerWallet->showBalance($reseller, $chatId) : null,
            default => null,
        };

        return in_array($text, [
            '🛒 خرید اکانت', '📦 اکانت‌های من', '💰 شارژ حساب',
            '🎁 دعوت از دوستان', '📚 قوانین و آموزش', '🎧 پشتیبانی',
            '💰 شارژ حساب نماینده',
        ], true);
    }

    protected function routeFreeText(Reseller $reseller, int $chatId, User $user, string $text): void
    {
        $step = $this->state->find($reseller, $chatId)->step;

        match ($step) {
            ConversationState::WALLET_AWAITING_AMOUNT => $this->wallet->handleCustomAmountText($reseller, $chatId, $user, $text),
            ConversationState::WALLET_AWAITING_DEPOSITOR_NAME => $this->wallet->handleDepositorName($reseller, $chatId, $user, $text),
            ConversationState::RESELLER_WALLET_AWAITING_AMOUNT => $this->resellerWallet->handleCustomAmountText($reseller, $chatId, $user, $text),
            ConversationState::RESELLER_WALLET_AWAITING_DEPOSITOR_NAME => $this->resellerWallet->handleDepositorName($reseller, $chatId, $user, $text),
            default => $this->start->showMainMenu($reseller, $chatId, $user),
        };
    }

    protected function routeCallback(Reseller $reseller, int $chatId, User $user, string $data): void
    {
        [$domain, $action, $value] = array_pad(explode(':', $data, 3), 3, null);

        match ("{$domain}:{$action}") {
            'rbuy:category' => $this->buy->showProducts($reseller, $chatId, $user, (int) $value),
            'rbuy:product' => $this->buy->purchase($reseller, $chatId, $user, (int) $value),
            'rbuy:back_to_categories' => $this->buy->start($reseller, $chatId, $user),
            'rwallet:amount' => $this->wallet->chooseAmount($reseller, $chatId, $user, (string) $value),
            'rwallet:method' => $this->wallet->chooseMethod($reseller, $chatId, $user, (int) $value),
            'rswallet:amount' => $this->requireOwner($reseller, $user) ? $this->resellerWallet->chooseAmount($reseller, $chatId, $user, (string) $value) : null,
            'rswallet:method' => $this->requireOwner($reseller, $user) ? $this->resellerWallet->chooseMethod($reseller, $chatId, $user, (int) $value) : null,
            'raccount:renew' => $this->accounts->renew($reseller, $chatId, $user, (int) $value),
            'raccount:config' => $this->accounts->sendConfig($reseller, $chatId, $user, (int) $value),
            'rpay:approve' => $this->paymentReview->approve($reseller, $chatId, $user, (int) $value),
            'rpay:reject' => $this->paymentReview->reject($reseller, $chatId, $user, (int) $value),
            default => null,
        };
    }

    protected function requireOwner(Reseller $reseller, User $user): bool
    {
        return $this->resellerService->isOwner($reseller, $user);
    }

    /**
     * طبق تصمیم صریح، فعلاً هیچ منوی مدیریتی داخل ربات نماینده لازم
     * نیست — «ادمین»/«/admin» فقط لینک پنل وب همان نماینده را می‌فرستد
     * (بند ۴ سند نیازمندی). پنل وب نماینده هنوز ساخته نشده (R5)، پس
     * فعلاً به یک مسیر placeholder اشاره می‌کند.
     */
    protected function handleAdminCommand(Reseller $reseller, int $chatId, User $user): void
    {
        if (! $this->resellerService->isOwner($reseller, $user)) {
            return;
        }

        $loginUrl = URL::temporarySignedRoute(
            'reseller.login',
            now()->addMinutes(10),
            ['reseller' => $reseller->id]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "🔑 برای ورود به پنل مدیریت فروشگاه (فقط برای ۱۰ دقیقه معتبر است):\n{$loginUrl}",
        ]);
    }
}
