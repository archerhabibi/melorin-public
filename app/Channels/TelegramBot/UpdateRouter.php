<?php

namespace App\Channels\TelegramBot;

use App\Support\Money;
use App\Channels\TelegramBot\Handlers\AccountsHandler;
use App\Channels\TelegramBot\Handlers\BuyAccountHandler;
use App\Channels\TelegramBot\Handlers\MiscHandler;
use App\Channels\TelegramBot\Handlers\StartHandler;
use App\Channels\TelegramBot\Handlers\WalletHandler;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Models\User;
use App\Services\Core\WalletService;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

/**
 * مسیریابی مرکزی: هر Update ورودی را بر اساس نوع پیام (متن دکمه‌ی منو،
 * کال‌بک این‌لاین، یا پاسخ آزاد در میانه‌ی یک جریان) به هندلر مناسب
 * می‌فرستد. این تنها فایلی است که «منطق مسیریابی» در آن است — منطق
 * کسب‌وکار همیشه داخل هندلرها و از آنجا داخل Core Services است.
 */
class UpdateRouter
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected StartHandler $start,
        protected BuyAccountHandler $buy,
        protected WalletHandler $wallet,
        protected AccountsHandler $accounts,
        protected MiscHandler $misc,
        protected WalletService $walletService,
    ) {}

    public function handle(Update $update, User $user, int $chatId): void
    {
        // مطابق همان نکته‌ی WebhookController: عمداً get('callback_query')/
        // get('message') به‌جای getCallbackQuery()/getMessage() استفاده
        // می‌شود، برای یکدست ماندن رفتار در کل جریان پردازش یک Update.
        $callbackQuery = $update->get('callback_query');

        if ($callbackQuery) {
            $this->telegram->answerCallbackQuery(['callback_query_id' => $callbackQuery->get('id')]);
            $this->routeCallback($chatId, $user, (string) $callbackQuery->get('data'));

            return;
        }

        $message = $update->get('message');

        if (! $message) {
            return;
        }

        $photos = $message->get('photo');

        if ($photos) {
            $largest = collect($photos)->last();
            $step = $this->state->find($chatId)->step;

            if ($step === ConversationState::WALLET_AWAITING_RECEIPT) {
                $this->wallet->handleReceiptPhoto($chatId, $user, $largest['file_id']);
            }

            return;
        }

        $text = trim((string) $message->getText());

        if (str_starts_with($text, '/start')) {
            $payload = trim(substr($text, strlen('/start'))) ?: null;
            $this->start->handle($chatId, $user, $payload);

            return;
        }

        if (trim($text) === 'ادمین') {
            $this->handleAdminCommand($chatId, $user);

            return;
        }

        // منوی اصلی همیشه بر اساس متن ثابت دکمه‌ها تشخیص داده می‌شود،
        // مستقل از وضعیت مکالمه‌ی فعلی — یعنی کاربر همیشه می‌تواند با زدن
        // یک دکمه‌ی منو، از وسط یک جریان خارج شود.
        $mainMenuRoute = $this->routeMainMenuText($chatId, $user, $text);
        if ($mainMenuRoute) {
            return;
        }

        $this->routeFreeText($chatId, $user, $text);
    }

    protected function routeMainMenuText(int $chatId, User $user, string $text): bool
    {
        match ($text) {
            '🛒 خرید اکانت' => $this->buy->start($chatId, $user),
            '🔍 استعلام و تمدید اکانت' => $this->accounts->list($chatId, $user),
            '💰 کیف پول و شارژ حساب' => $this->wallet->showBalance($chatId, $user),
            '👤 حساب کاربری' => $this->showProfile($chatId, $user),
            '🎁 دعوت از دوستان' => $this->misc->referral($chatId, $user),
            '🧪 دریافت اکانت تست' => $this->misc->testAccount($chatId, $user),
            '📜 قوانین خرید و آموزش' => $this->misc->rules($chatId),
            '🎧 پشتیبانی' => $this->misc->supportStart($chatId, $user),
            '🤖 درخواست ربات نماینده و همکاری' => $this->misc->resellerRequestStart($chatId, $user),
            default => null,
        };

        return match ($text) {
            '🛒 خرید اکانت', '🔍 استعلام و تمدید اکانت', '💰 کیف پول و شارژ حساب',
            '👤 حساب کاربری', '🎁 دعوت از دوستان', '🧪 دریافت اکانت تست',
            '📜 قوانین خرید و آموزش', '🎧 پشتیبانی', '🤖 درخواست ربات نماینده و همکاری' => true,
            default => false,
        };
    }

    protected function routeFreeText(int $chatId, User $user, string $text): void
    {
        $step = $this->state->find($chatId)->step;

        match ($step) {
            ConversationState::WALLET_AWAITING_AMOUNT => $this->wallet->handleCustomAmountText($chatId, $user, $text),
            ConversationState::WALLET_AWAITING_DEPOSITOR_NAME => $this->wallet->handleDepositorName($chatId, $user, $text),
            ConversationState::SUPPORT_AWAITING_MESSAGE => $this->misc->supportSubmit($chatId, $user, $text),
            ConversationState::BUY_AWAITING_CUSTOM_NAME => $this->buy->handleCustomNameText($chatId, $user, $text),
            ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION => $this->misc->resellerRequestSubmit($chatId, $user, $text),
            ConversationState::TICKET_AWAITING_REPLY => $this->misc->ticketReplySubmit($chatId, $user, $text),
            default => $this->start->showMainMenu($chatId),
        };
    }

    protected function routeCallback(int $chatId, User $user, string $data): void
    {
        [$domain, $action, $value] = array_pad(explode(':', $data, 3), 3, null);

        match ("{$domain}:{$action}") {
            'buy:category' => $this->buy->showProducts($chatId, $user, (int) $value),
            'buy:product' => $this->buy->chooseServerOrPurchase($chatId, $user, (int) $value),
            'buy:server' => $this->routeBuyServer($chatId, $user, (string) $value),
            'buy:back_to_categories' => $this->buy->start($chatId, $user),
            'wallet:amount' => $this->wallet->chooseAmount($chatId, $user, (string) $value),
            'wallet:method' => $this->wallet->chooseMethod($chatId, $user, (int) $value),
            'account:renew' => $this->accounts->renew($chatId, $user, (int) $value),
            'account:config' => $this->accounts->sendConfig($chatId, $user, (int) $value),
            default => null,
        };
    }

    /**
     * callback_data برای انتخاب دستی سرور به‌صورت «buy:server:{productId}_{panelId}»
     * است — هر دو شناسه در یک تکه‌ی $value، چون routeCallback حداکثر تا
     * سومین «:» می‌شکند و نمی‌توان یک «:» دیگر اضافه کرد.
     */
    protected function routeBuyServer(int $chatId, User $user, string $value): void
    {
        [$productId, $panelId] = array_pad(explode('_', $value, 2), 2, null);

        if (! $productId || ! $panelId) {
            return;
        }

        $this->buy->proceedAfterServer($chatId, $user, (int) $productId, (int) $panelId);
    }

    /**
     * تشخیص عبارت «ادمین» طبق بند ۳ سند نیازمندی. مدیریت کامل هنوز فقط
     * از طریق پنل وب Filament (بند ۲۲) انجام می‌شود — این فقط دسترسی را
     * تایید و کاربر را به پنل هدایت می‌کند؛ منوی مدیریت کامل داخل ربات
     * در فاز بعدی اضافه می‌شود.
     */
    protected function handleAdminCommand(int $chatId, User $user): void
    {
        $allowedIds = config('telegram.admin_ids', []);
        $telegramId = (string) $user->telegram_id;

        if (! in_array($telegramId, $allowedIds, true)) {
            // عمداً پیام «دسترسی ندارید» نمی‌فرستیم تا کاربران غیرمجاز
            // متوجه وجود این دستور نشوند؛ فقط به منوی عادی برمی‌گردیم.
            $this->start->showMainMenu($chatId);

            return;
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "🔑 دسترسی ادمین تایید شد.\n\nمدیریت کامل سیستم (سرورها، محصولات، پرداخت‌ها، کاربران) از طریق پنل مدیریت تحت وب انجام می‌شود:\n".config('app.url').'/admin',
        ]);
    }

    /**
     * طبق درخواست صریح: «حساب کاربری» و «کیف پول» باید در یک نگاه دیده
     * شوند، و شناسه‌ی نمایشی باید همان شناسه‌ی عددی تلگرام (telegram_id)
     * باشد — نه id داخلی دیتابیس، چون آن عدد برای خودِ کاربر و ادمین در
     * تطبیق با تلگرام بی‌معنی و گیج‌کننده است.
     */
    protected function showProfile(int $chatId, User $user): void
    {
        $balance = $this->walletService->balance($user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "👤 حساب کاربری\n\n"
                ."شناسه‌ی تلگرام: {$user->telegram_id}\n"
                ."نام: {$user->full_name}\n"
                ."تاریخ عضویت: {$user->created_at->format('Y-m-d')}\n"
                .'💰 موجودی کیف پول: '.Money::format($balance),
        ]);
    }
}
