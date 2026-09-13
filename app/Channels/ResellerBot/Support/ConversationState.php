<?php

namespace App\Channels\ResellerBot\Support;

use App\Models\Reseller;
use App\Models\ResellerConversationState;
use App\Models\User;

/**
 * دقیقاً هم‌شکل با App\Channels\TelegramBot\Support\ConversationState،
 * با یک فرق کلیدی: همه‌چیز نسبت به یک Reseller مشخص Scope می‌شود —
 * دلیلش در کامنت migration جدول reseller_conversation_states توضیح
 * داده شده (تداخل احتمالی chat_id بین ربات‌های مختلف).
 */
class ConversationState
{
    public const IDLE = 'idle';

    public const BUY_CHOOSE_CATEGORY = 'buy:choose_category';

    public const BUY_CHOOSE_PRODUCT = 'buy:choose_product';

    // شارژ کیف‌پول شخصیِ مشتری — تاییدش با خودِ نماینده است
    public const WALLET_AWAITING_AMOUNT = 'wallet:awaiting_amount';

    public const WALLET_CHOOSE_METHOD = 'wallet:choose_method';

    public const WALLET_AWAITING_RECEIPT = 'wallet:awaiting_receipt';

    public const WALLET_AWAITING_DEPOSITOR_NAME = 'wallet:awaiting_depositor_name';

    // شارژ کیف‌پول/اعتبار خودِ نماینده نزد پلتفرم — تاییدش با ادمین اصلی است
    public const RESELLER_WALLET_AWAITING_AMOUNT = 'reseller_wallet:awaiting_amount';

    public const RESELLER_WALLET_CHOOSE_METHOD = 'reseller_wallet:choose_method';

    public const RESELLER_WALLET_AWAITING_RECEIPT = 'reseller_wallet:awaiting_receipt';

    public const RESELLER_WALLET_AWAITING_DEPOSITOR_NAME = 'reseller_wallet:awaiting_depositor_name';

    public function find(Reseller $reseller, int $chatId): ResellerConversationState
    {
        return ResellerConversationState::firstOrCreate(
            ['reseller_id' => $reseller->id, 'telegram_chat_id' => $chatId],
            ['step' => self::IDLE, 'payload' => []]
        );
    }

    public function set(Reseller $reseller, int $chatId, string $step, array $payload = [], ?User $user = null): ResellerConversationState
    {
        $state = $this->find($reseller, $chatId);
        $state->update([
            'step' => $step,
            'payload' => $payload,
            'user_id' => $user?->id ?? $state->user_id,
        ]);

        return $state;
    }

    public function reset(Reseller $reseller, int $chatId): void
    {
        $this->find($reseller, $chatId)->update(['step' => self::IDLE, 'payload' => []]);
    }
}
