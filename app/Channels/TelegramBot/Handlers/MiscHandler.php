<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Support\Money;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Events\TicketCreated;
use App\Events\TicketUserReplied;
use App\Models\Account;
use App\Models\AffiliateSetting;
use App\Models\BotContentSetting;
use App\Models\TestAccountSetting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Core\AccountService;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

/**
 * پیاده‌سازی سبک‌وزنِ باقی‌مانده‌ی منوی اصلی (بند ۳.۱): زیرمجموعه‌گیری،
 * پشتیبانی و قوانین. این‌ها MVP هستند؛ گزارش کامل کمیسیون (بند ۱۳) و
 * تیکتینگ کامل (بند ۱۶) باید در فاز بعدی تکمیل شوند — فعلاً فقط ثبت اولیه.
 */
class MiscHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected AccountService $accountService,
        // برای reuse مستقیم deliverConfig() به‌جای کپی/تکرار منطق تحویل
        // کانفیگ+QR که در BuyAccountHandler از قبل تست‌شده وجود دارد
        protected BuyAccountHandler $buyAccountHandler,
    ) {}

    public function referral(int $chatId, User $user): void
    {
        $settings = AffiliateSetting::current();
        $botUsername = config('telegram.bots.main.username', 'MelorinBot');
        $bonus = (int) $settings->referrer_bonus_amount;

        // قبلاً این پیام وعده‌ی «پاداش خرید اول» و «کمیسیون خریدهای بعدی» را
        // هم می‌داد، در حالی که هیچ‌کدام پرداخت نمی‌شدند (Commission هیچ‌جا
        // create نمی‌شد). فقط همان پاداشی که واقعاً در StartHandler پرداخت
        // می‌شود اینجا تبلیغ می‌شود.
        $bonusLine = $bonus > 0
            ? '🎁 با عضویت هر نفر از طریق این لینک، '.Money::format($bonus)." به کیف پول شما اضافه می‌شود.\n"
            : '';

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '🎁 لینک دعوت اختصاصی شما:'."\n".'<code>https://t.me/'.htmlspecialchars($botUsername, ENT_QUOTES)."?start={$user->id}</code>\n\n"
                ."تعداد زیرمجموعه‌ها: {$user->referredUsers()->count()}\n"
                .$bonusLine,
            'parse_mode' => 'HTML',
        ]);
    }

    public function rules(int $chatId): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => BotContentSetting::current()->purchaseRulesText(),
        ]);
    }

    /**
     * «🤖 درخواست ربات نماینده و همکاری» — طبق درخواست صریح، دو مسیر
     * کاملاً جدا دارد:
     *
     * - ادمین ربات (isBotAdmin — یعنی خودِ صاحب/اپراتور همین استقرار
     *   Melorin): وارد جریان درخواست نمی‌شود، چون او که ربات را نصب
     *   کرده نیازی به «درخواست» ربات نماینده از خودش ندارد؛ چیزی که
     *   نیاز دارد نسخه‌ی Pro برای فعال‌سازی امکان ربات نماینده است — یک
     *   پیام ثابت با لینک نشان داده می‌شود.
     * - کاربر عادی: اگر از قبل یک درخواستِ بازِ خودش دارد، به‌جای شروع
     *   یک تیکت جدید، به همان گفتگو ادامه می‌دهیم (promptTicketReply).
     *   وگرنه منتظر توضیحات می‌ماند تا در resellerRequestSubmit اولین
     *   پیامِ یک تیکت جدید (type=reseller_request) شود.
     */
    public function resellerRequestStart(int $chatId, User $user): void
    {
        if ($user->isBotAdmin()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "برای دریافت و فعال‌سازی ربات نماینده نیاز به نسخه‌ی پرو می‌باشد.\nhttps://t.me/melorinpro\nبرای اطلاعات بیشتر به لینک بالا مراجعه شود.",
            ]);

            return;
        }

        $openTicket = Ticket::query()
            ->where('user_id', $user->id)
            ->whereNull('reseller_id')
            ->where('type', 'reseller_request')
            ->where('status', '!=', 'closed')
            ->latest()
            ->first();

        if ($openTicket) {
            $this->promptTicketReply($chatId, $user, $openTicket);

            return;
        }

        $this->state->set($chatId, ConversationState::RESELLER_REQUEST_AWAITING_DESCRIPTION, [], $user);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '📌 توضیحات خود را برای ثبت درخواست نمایندگی ارسال نمایید.',
        ]);
    }

    /**
     * طبق درخواست صریح، درخواست نمایندگی از این نسخه به بعد یک پیام
     * خامِ broadcast‌شده به ادمین‌ها نیست، بلکه یک تیکتِ واقعی (نوعش
     * reseller_request) است — دقیقاً هم‌الگو با supportSubmit — تا از
     * همان پنل تیکت‌ها و همان زیرساخت گفتگوی دوطرفه قابل‌پاسخ باشد.
     * TicketCreated (که NotifyAdminsOfNewTicket از قبل به آن گوش
     * می‌دهد) همان broadcast به همه‌ی admin_ids را انجام می‌دهد؛ دیگر
     * نیازی به یک لوپ جداگانه نبود.
     */
    public function resellerRequestSubmit(int $chatId, User $user, string $text): void
    {
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'type' => 'reseller_request',
            'subject' => '🤖 درخواست ربات نماینده و همکاری',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => $text,
        ]);

        $this->state->reset($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ درخواست شما با شماره‌ی #{$ticket->id} ثبت و برای بررسی ارسال شد. به‌زودی با شما تماس گرفته می‌شود.",
        ]);

        TicketCreated::dispatch($ticket->fresh());
    }

    /**
     * پیاده‌سازی کامل «اکانت تست» (طبق سند ۰۷: قابلیت باید یا کامل باشد
     * یا از منو مخفی — نه پیام «به‌زودی» دائمی). دکمه‌ی منو خودش هم فقط
     * وقتی نشان داده می‌شود که تنظیمات usable باشد (ر.ک.
     * Keyboards::mainMenu)، پس رسیدن به اینجا با تنظیمات غیرفعال باید
     * نادر باشد — ولی برای اطمینان دوباره چک می‌شود، چون کاربر می‌تواند
     * متن دکمه را دستی هم بفرستد.
     */
    public function testAccount(int $chatId, User $user): void
    {
        $settings = TestAccountSetting::current();

        if (! $settings->isUsable()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'دریافت اکانت تست در حال حاضر غیرفعال است.',
            ]);

            return;
        }

        $usedCount = Account::query()
            ->where('user_id', $user->id)
            ->where('is_test', true)
            ->count();

        if ($usedCount >= $settings->max_per_user) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "شما قبلاً از سهمیه‌ی اکانت تست خود استفاده کرده‌اید (حداکثر مجاز: {$settings->max_per_user} بار).",
            ]);

            return;
        }

        $product = $settings->product;

        if (! $product || $product->status !== 'active') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'دریافت اکانت تست موقتاً در دسترس نیست — لطفاً بعداً تلاش کنید.',
            ]);

            return;
        }

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '🧪 در حال ساخت اکانت تست شما...']);

        // طبق درخواست صریح: نام اکانت تست باید آیدی تلگرام یا نام تلگرام
        // کاربر باشد — مستقل از naming_mode سبد فروش (که برای خرید واقعی
        // است). آیدی عددی تلگرام همیشه در دسترس و همیشه یکتاست؛ نام
        // کامل فقط به‌عنوان fallback اگر به هر دلیلی خالی باشد.
        $testUsername = $user->telegram_id
            ? 'tg'.$user->telegram_id
            : Str::slug($user->full_name ?: 'test', '_');

        try {
            $account = $this->accountService->purchase(
                $user,
                $product,
                salesChannel: 'test_account',
                customUsername: $testUsername,
                isTest: true,
                testTrafficMb: $settings->traffic_mb,
                testDurationHours: $settings->duration_hours,
            );
        } catch (\RuntimeException $e) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "دریافت اکانت تست ناموفق بود: {$e->getMessage()}\nلطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.",
            ]);

            return;
        }

        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '✅ اکانت تست شما ساخته شد.']);
        $this->buyAccountHandler->deliverConfig($chatId, $account);
    }

    public function supportStart(int $chatId, User $user): void
    {
        // B3.4: ربات فقط فروشگاه اصلی است؛ تیکت ثبت‌شده در فروشگاه یک نماینده (reseller_id) اینجا ظاهر نمی‌شود.
        $openTicket = Ticket::query()
            ->where('user_id', $user->id)
            ->whereNull('reseller_id')
            ->where('type', 'support')
            ->where('status', '!=', 'closed')
            ->latest()
            ->first();

        if ($openTicket) {
            $this->promptTicketReply($chatId, $user, $openTicket);

            return;
        }

        $this->state->set($chatId, ConversationState::SUPPORT_AWAITING_MESSAGE, [], $user);
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => '🎧 پیام خود را برای پشتیبانی بنویسید:']);
    }

    public function supportSubmit(int $chatId, User $user, string $text): void
    {
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'type' => 'support',
            'subject' => mb_substr($text, 0, 60),
            'status' => 'open',
            'priority' => 'normal',
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => $text,
        ]);

        $this->state->reset($chatId);
        $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "✅ تیکت شما با شماره‌ی #{$ticket->id} ثبت شد. پشتیبانی به‌زودی پاسخ می‌دهد."]);

        // پیش از این نسخه، تیکت فقط در دیتابیس ثبت می‌شد و هیچ‌کس مطلع
        // نمی‌شد (بند «پشتیبانی نیمه‌کاره»). این رویداد به ادمین‌های
        // تلگرام اطلاع می‌دهد که پاسخ باید از پنل داده شود.
        TicketCreated::dispatch($ticket->fresh());
    }

    /**
     * وقتی کاربر یک تیکت باز/پاسخ‌داده‌شده از قبل دارد (پشتیبانی یا
     * درخواست نمایندگی — فرقی نمی‌کند) و دوباره روی همان دکمه‌ی منو
     * می‌زند، به‌جای باز کردن یک تیکت جدید، آخرین چند پیام را نشان
     * می‌دهیم و منتظر پیام بعدی می‌مانیم تا به همین تیکت اضافه شود.
     * دقیقاً همین امکان — که کاربر هر وقت بخواهد بتواند ادامه بدهد، نه
     * فقط یک‌بار در همان ابتدا — همان «حالت Conversation و مکالمه»یی
     * است که طبق درخواست صریح باید در همه‌ی تیکت‌ها باشد.
     */
    protected function promptTicketReply(int $chatId, User $user, Ticket $ticket): void
    {
        $recap = $ticket->messages()
            ->latest()
            ->take(3)
            ->get()
            ->reverse()
            ->map(fn (TicketMessage $m) => ($m->sender_type === 'admin' ? '👨‍💼 پشتیبانی: ' : '🧑 شما: ').mb_substr($m->message, 0, 200))
            ->implode("\n\n");

        $this->state->set($chatId, ConversationState::TICKET_AWAITING_REPLY, ['ticket_id' => $ticket->id], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "شما یک تیکت باز دارید (#{$ticket->id}):\n\n{$recap}\n\n💬 پیام خود را بفرستید تا به همین تیکت اضافه شود.",
        ]);
    }

    /**
     * پیامی که کاربر بعد از promptTicketReply می‌فرستد — به همان تیکت
     * اضافه می‌شود، نه یک تیکت جدید. اگر تیکت answered بود، وضعیتش به
     * open برمی‌گردد (چون دوباره منتظر پاسخ ادمین است). اگر بین نمایش
     * prompt و ارسال پیام، ادمین از پنل تیکت را بسته باشد، پیام کاربر
     * همچنان ثبت می‌شود و تیکت دوباره open می‌شود — چون از دید کاربر،
     * او هنوز به کمک نیاز دارد.
     *
     * چک user_id در کوئری Ticket::find عمداً است: حتی اگر یک ticket_id
     * قدیمی/دستکاری‌شده در payload باشد، کاربر نمی‌تواند به تیکت شخص
     * دیگری پیام اضافه کند.
     */
    public function ticketReplySubmit(int $chatId, User $user, string $text): void
    {
        $ticketId = $this->state->find($chatId)->payload['ticket_id'] ?? null;

        $this->state->reset($chatId);

        $ticket = $ticketId
            ? Ticket::query()->where('user_id', $user->id)->whereNull('reseller_id')->find($ticketId)
            : null;

        if (! $ticket) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'این تیکت دیگر در دسترس نیست. لطفاً دوباره از منو شروع کنید.',
            ]);

            return;
        }

        $message = $ticket->messages()->create([
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'message' => $text,
        ]);

        $ticket->update(['status' => 'open']);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ پیام شما به تیکت #{$ticket->id} اضافه شد.",
        ]);

        TicketUserReplied::dispatch($message->fresh());
    }
}
