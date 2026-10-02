<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Support\Money;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\Support\Keyboards;
use App\Models\AffiliateSetting;
use App\Models\TestAccountSetting;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Api;

/**
 * دستور /start و بازگشت به منوی اصلی. طبق بند ۱۳ سند، اگر کاربر از طریق
 * لینک دعوت (deep-link payload = آیدی معرف) وارد شده و قبلاً معرفی‌شده
 * نبوده، referrer_id ثبت می‌شود.
 *
 * طبق تصمیم صریح (رفع باگ گزارش‌شده: لینک دعوت وعده‌ی «پاداش X تومان»
 * می‌داد ولی هیچ‌جا واریزش نمی‌شد)، عمداً فقط همین یک نوع پاداش
 * پیاده‌سازی شده: مبلغ ثابت referrer_bonus_amount به معرف، دقیقاً یک‌بار
 * در لحظه‌ای که referrer_id برای اولین‌بار روی کاربر ثبت می‌شود — نه
 * کمیسیون خرید و نه پاداش به خودِ کاربر تازه‌وارد؛ آن دو طبق تصمیم صریح
 * فعلاً پیاده‌سازی نمی‌شوند (Commission همچنان بدون استفاده می‌ماند).
 */
class StartHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected WalletService $walletService,
    ) {}

    public function handle(int $chatId, User $user, ?string $payload): void
    {
        $this->state->reset($chatId);

        if ($payload && is_numeric($payload) && ! $user->referrer_id && (int) $payload !== $user->id) {
            $this->registerReferral($user, (int) $payload);
        }

        $this->showMainMenu($chatId);
    }

    /**
     * معرف را ثبت و پاداش عضویت را پرداخت می‌کند — دقیقاً و فقط یک‌بار
     * برای هر کاربر.
     *
     * اصلاحِ مهم نسبت به نسخه‌ی اول این پچ: پرداخت پاداش دیگر به
     * $user->wasRecentlyCreated گره نخورده — آن شرط، کاربرانی را که از
     * قبل در دیتابیس بودند ولی هنوز هیچ معرفی نداشتند (مثلاً کاربری که
     * بدون لینک دعوت با ربات شروع کرده و بعداً روی لینک یک نفر کلیک
     * می‌کند) از پاداش محروم می‌کرد، در حالی که طبق بند ۱۳ سند، پرداخت
     * باید به «اولین ثبت referrer_id» گره بخورد نه به سنِ ردیف کاربر.
     *
     * چون referrer_id فقط زمانی نال است که کاربر تا الان هیچ معرفی
     * نداشته، این شرط خودش idempotency را تضمین می‌کند: هر معرف برای هر
     * کاربرِ خاص فقط یک‌بار پاداش می‌گیرد و /startهای بعدی با همان لینک
     * چیزی پرداخت نمی‌کنند. برای اطمینان در برابر وب‌هوک‌های هم‌زمان یا
     * تکراری تلگرام (که می‌توانند برای یک درخواست چند بار ارسال شوند)،
     * ثبت referrer_id با یک UPDATE شرطیِ اتمیک (whereNull) انجام می‌شود،
     * نه با «خواندن، سپس نوشتن» جدا — پس حتی اگر دو درخواست هم‌زمان به
     * اینجا برسند، فقط یکی از آن‌ها موفق به claim کردن می‌شود و پاداش
     * دقیقاً یک‌بار پرداخت می‌شود.
     */
    protected function registerReferral(User $user, int $referrerId): void
    {
        $referrer = User::find($referrerId);

        if (! $referrer) {
            return;
        }

        $claimed = User::query()
            ->whereKey($user->id)
            ->whereNull('referrer_id')
            ->update(['referrer_id' => $referrer->id]);

        if ($claimed !== 1) {
            // referrer_id بین خواندن و اینجا توسط یک درخواست هم‌زمان دیگر
            // (مثلاً وب‌هوک تکراری تلگرام) ست شده — پاداش نباید دوباره
            // پرداخت شود.
            return;
        }

        $user->refresh();

        $bonus = (int) AffiliateSetting::current()->referrer_bonus_amount;

        // اگر ادمین هنوز مبلغی تنظیم نکرده (مقدار پیش‌فرض ۰)، بی‌سروصدا
        // رد می‌شویم — addReferralBonus() برای مبلغ صفر/منفی استثنا پرت
        // می‌کند (assertPositive) و نباید کل جریان /start را با آن خراب
        // کنیم. توجه: referrer_id در هر صورت (حتی با بونس صفر) ثبت شده و
        // ماندگار می‌ماند.
        if ($bonus > 0) {
            $this->walletService->addReferralBonus(
                $referrer,
                $bonus,
                $user,
                "پاداش دعوت: عضویت {$user->full_name}"
            );

            // طبق درخواست صریح: بعد از شارژ شدن حساب معرف، یک پیام هم
            // برای اطلاع خودِ او فرستاده شود — قبلاً معرف هیچ‌وقت متوجه
            // نمی‌شد پاداشش پرداخت شده مگر خودش موجودی کیف پول را چک
            // می‌کرد. اگر معرف telegram_id نداشته باشد (مثلاً فقط از
            // سایت ثبت‌نام کرده)، جایی برای ارسال پیام نیست و رد می‌شویم —
            // خود پرداختِ پاداش (بالا) در هر صورت انجام شده است.
            if ($referrer->telegram_id) {
                $this->telegram->sendMessage([
                    'chat_id' => $referrer->telegram_id,
                    'text' => "🎉 یک کاربر جدید با لینک دعوت شما عضو ربات شد!\n"
                        .Money::format($bonus)." به کیف پول شما اضافه شد.\n"
                        .'موجودی فعلی: '.Money::format($this->walletService->balance($referrer)),
                ]);
            }
        }
    }

    public function showMainMenu(int $chatId): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "به {$this->botDisplayName()} خوش آمدید 🌐",
            'reply_markup' => Keyboards::mainMenu(TestAccountSetting::current()->isUsable()),
        ]);
    }

    /**
     * قبلاً این متن هاردکد «ربات ملورین» بود — اگر کسی ربات را از
     * BotFather با نام دیگری بسازد یا بعداً rename کند، پیام خوش‌آمد
     * دیگر با نام واقعی ربات یکی نبود (طبق درخواست صریح). به‌جای یک
     * تنظیمِ دستی دیگر در پنل که ممکن است با تغییر نام واقعی ربات
     * هماهنگ نماند، مستقیم از Telegram getMe() خوانده می‌شود — یعنی
     * همیشه با نام واقعیِ ربات یکی است، بدون نیاز به هیچ اقدام دستی.
     * برای این‌که هر /start یک درخواست اضافه به API تلگرام نزند، ۲۴
     * ساعت کش می‌شود (نام ربات عملاً هیچ‌وقت در میانه‌ی روز عوض نمی‌شود).
     */
    protected function botDisplayName(): string
    {
        return Cache::remember(
            'telegram_bot_display_name',
            now()->addDay(),
            fn () => $this->telegram->getMe()->getFirstName() ?: 'ربات ملورین'
        );
    }
}
