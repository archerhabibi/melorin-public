<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\Support\Keyboards;
use App\Models\User;
use App\Services\Core\Customer\ProfileCenterException;
use App\Services\Core\Customer\ProfileCenterService;
use App\Services\Core\Customer\ProfileOverview;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Support\JalaliDate;
use App\Support\Money;
use Telegram\Bot\Api;

/**
 * «👤 حساب کاربری» (B3.5). Adapter نازک روی `ProfileCenterService`: همان Core و همان اعتبارسنجی که صفحه‌ی
 * Profile در Website استفاده می‌کند؛ این‌جا فقط متن/کیبورد و State مکالمه است. قرارداد: `CUSTOMER-PROFILE-CONTRACT.md`.
 *
 * ربات فقط فروشگاه اصلی است ⇒ `StoreContext::main()`. ایمیل و روش‌های ورود از ربات ویرایش نمی‌شوند (D-12).
 */
class ProfileHandler
{
    /** در مرحله‌ی موبایل، این کلمات یعنی «شماره را حذف کن» */
    protected const CLEAR_WORDS = ['-', 'حذف'];

    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected ProfileCenterService $profile,
        protected WalletService $walletService,
    ) {}

    public function show(int $chatId, User $user): void
    {
        // کاربر از وسط یک جریان ویرایش هم می‌تواند با زدن این دکمه‌ی منو به صفحه‌ی اصلی برگردد.
        $this->state->reset($chatId);

        $overview = $this->profile->overview($user, StoreContext::main());

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $this->summary($user, $overview),
            'reply_markup' => Keyboards::profileActions(),
        ]);
    }

    /** خروج از جریان ویرایش (زدن یکی از دکمه‌های منوی اصلی). فقط مرحله‌های Profile را پاک می‌کند، نه جریان‌های دیگر. */
    public function abandonEdit(int $chatId): void
    {
        if (in_array($this->state->find($chatId)->step, [ConversationState::PROFILE_AWAITING_NAME, ConversationState::PROFILE_AWAITING_PHONE], true)) {
            $this->state->reset($chatId);
        }
    }

    /** callback `profile:edit:{name|phone}` */
    public function editStart(int $chatId, User $user, string $field): void
    {
        [$step, $prompt] = match ($field) {
            'name' => [ConversationState::PROFILE_AWAITING_NAME, '✏️ نام و نام خانوادگی جدید را بفرستید:'],
            'phone' => [ConversationState::PROFILE_AWAITING_PHONE, "📱 شماره‌ی موبایل را بفرستید (مثل 09123456789).\nبرای حذف شماره «-» بفرستید."],
            default => [null, null],
        };

        if ($step === null) {
            return;
        }

        $this->state->set($chatId, $step, [], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $prompt."\n\nبرای انصراف یکی از گزینه‌های منو را بزنید.",
        ]);
    }

    public function submitName(int $chatId, User $user, string $text): void
    {
        $this->submit($chatId, $user, ConversationState::PROFILE_AWAITING_NAME, 'full_name', $text);
    }

    public function submitPhone(int $chatId, User $user, string $text): void
    {
        $value = in_array(trim($text), self::CLEAR_WORDS, true) ? null : $text;

        $this->submit($chatId, $user, ConversationState::PROFILE_AWAITING_PHONE, 'phone', $value);
    }

    protected function submit(int $chatId, User $user, string $step, string $field, ?string $value): void
    {
        try {
            $changed = $this->profile->update($user, [$field => $value]);
        } catch (ProfileCenterException $e) {
            // خطای ورودی: همان مرحله باز می‌ماند تا کاربر بتواند دوباره بفرستد.
            $this->state->set($chatId, $step, [], $user);
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '⚠️ '.$e->getMessage()]);

            return;
        }

        $this->state->reset($chatId);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $changed === [] ? 'ℹ️ تغییری ثبت نشد؛ مقدار قبلی همان است.' : '✅ ذخیره شد.',
        ]);

        $this->show($chatId, $user);
    }

    /**
     * طبق درخواست صریح: «حساب کاربری» و «کیف پول» در یک نگاه؛ شناسه‌ی نمایشی همان شناسه‌ی عددی تلگرام است
     * (نه id داخلی دیتابیس).
     */
    protected function summary(User $user, ProfileOverview $o): string
    {
        $lines = [
            '👤 حساب کاربری',
            '',
            "شناسه‌ی تلگرام: {$user->telegram_id}",
            'نام: '.($o->fullName ?? 'ثبت نشده'),
            'موبایل: '.($o->phone ?? 'ثبت نشده'),
            'ایمیل: '.($o->email === null ? 'ثبت نشده' : $o->email.($o->emailVerified ? ' ✅' : ' ⏳ تأییدنشده')),
        ];

        if ($o->memberSince) {
            $lines[] = 'تاریخ عضویت: '.JalaliDate::format($o->memberSince).' ('.$o->memberSince->format('Y-m-d').')';
        }

        $lines[] = '💰 موجودی کیف پول: '.Money::format($this->walletService->balance($user));

        if (! $o->isComplete()) {
            $labels = ProfileOverview::checkLabels();
            $lines[] = '';
            $lines[] = 'برای تکمیل پروفایل: '.collect($o->missing())->map(fn (string $k) => $labels[$k])->implode('، ');
        }

        return implode("\n", $lines);
    }
}
