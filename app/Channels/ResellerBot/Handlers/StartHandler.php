<?php

namespace App\Channels\ResellerBot\Handlers;

use App\Channels\ResellerBot\Support\ConversationState;
use App\Channels\ResellerBot\Support\Keyboards;
use App\Models\Reseller;
use App\Models\ResellerBotSetting;
use App\Models\User;
use App\Services\Resellers\ResellerService;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Api;

/**
 * دستور /start و بازگشت به منوی اصلی. طبق «اصل طلایی امنیت و جداسازی
 * داده»، اولین‌بار که یک User با این ربات صحبت می‌کند باید صراحتاً به
 * Scope همین Reseller متصل شود (بند ۱۶ سند نیازمندی: Customer
 * Assignment) — نه به‌صورت ضمنی جایی دیگر.
 */
class StartHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected ResellerService $resellerService,
    ) {}

    public function handle(Reseller $reseller, int $chatId, User $user, ?string $payload): void
    {
        $this->state->reset($reseller, $chatId);

        // اختصاص خودکار به این نماینده در اولین برخورد. اگر کاربر از قبل
        // مشتری یک نماینده‌ی دیگر است، طبق «اصل طلایی» اجازه‌ی ادامه در
        // این ربات را ندارد — این‌جا هم مثل AccountService::purchase()
        // enforce می‌شود، نه فقط در لایه‌ی UI.
        if ($user->reseller_id === null) {
            $user->update(['reseller_id' => $reseller->id]);
        } elseif ($user->reseller_id !== $reseller->id) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'این حساب قبلاً نزد یک نماینده‌ی دیگر ثبت شده و امکان استفاده از این ربات را ندارد.',
            ]);

            return;
        }

        if ($payload && str_starts_with($payload, 'ref_') && ! $user->referrer_id) {
            $this->registerReferral($reseller, $user, (int) substr($payload, 4));
        }

        $this->showMainMenu($reseller, $chatId, $user);
    }

    /**
     * فقط attribution؛ عمداً بدون پرداخت پاداش — سیستم افیلیت/کمیسیون
     * در سطح نماینده هنوز طراحی نشده (خارج از Scope فعلی طبق تصمیم صریح
     * تمرکز این فاز روی Identity/Wallet/Purchase/Bot است، نه Affiliate).
     */
    protected function registerReferral(Reseller $reseller, User $user, int $referrerId): void
    {
        if ($referrerId === $user->id) {
            return;
        }

        $referrer = User::query()->where('id', $referrerId)->where('reseller_id', $reseller->id)->first();

        if (! $referrer) {
            return;
        }

        User::query()->whereKey($user->id)->whereNull('referrer_id')->update(['referrer_id' => $referrer->id]);
    }

    public function showMainMenu(Reseller $reseller, int $chatId, User $user): void
    {
        $isOwner = $this->resellerService->isOwner($reseller, $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "به {$this->botDisplayName($reseller)} خوش آمدید 🌐",
            'reply_markup' => Keyboards::mainMenu($isOwner),
        ]);
    }

    /** هم‌الگو با StartHandler ربات اصلی، ولی کش هر نماینده جدا است چون نام هر ربات فرق دارد */
    protected function botDisplayName(Reseller $reseller): string
    {
        return Cache::remember(
            "telegram_bot_display_name:reseller:{$reseller->id}",
            now()->addDay(),
            fn () => $this->telegram->getMe()->getFirstName() ?: 'ربات فروش'
        );
    }

    public function rules(Reseller $reseller, int $chatId): void
    {
        $settings = ResellerBotSetting::forReseller($reseller);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $settings->rules
                ? "📜 قوانین خرید:\n\n{$settings->rules}"
                : 'قوانین خاصی برای این فروشگاه تنظیم نشده است.',
        ]);

        if ($settings->connection_guide) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "📚 آموزش اتصال:\n\n{$settings->connection_guide}",
            ]);
        }
    }

    public function support(Reseller $reseller, int $chatId): void
    {
        $settings = ResellerBotSetting::forReseller($reseller);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $settings->support_id
                ? "🎧 پشتیبانی:\n{$settings->support_id}"
                : 'در حال حاضر راه ارتباطی پشتیبانی ثبت نشده است.',
        ]);
    }

    public function referral(Reseller $reseller, int $chatId, User $user): void
    {
        $botUsername = Cache::remember(
            "telegram_bot_username:reseller:{$reseller->id}",
            now()->addDay(),
            fn () => $this->telegram->getMe()->getUsername()
        );

        $link = $botUsername ? "https://t.me/{$botUsername}?start=ref_{$user->id}" : null;

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $link
                ? "🎁 لینک دعوت شما:\n{$link}"
                : 'در حال حاضر امکان ساخت لینک دعوت وجود ندارد.',
        ]);
    }
}
