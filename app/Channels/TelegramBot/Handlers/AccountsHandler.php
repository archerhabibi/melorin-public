<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Support\Money;
use App\Channels\TelegramBot\Support\Keyboards;
use App\Channels\TelegramBot\Support\QrCodeGenerator;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

/**
 * جریان «🔍 استعلام و تمدید اکانت» (بند ۱۰ سند): مشاهده‌ی اکانت‌های
 * فعال، تاریخ انقضا، حجم باقی‌مانده، وضعیت، تمدید، و دریافت مجدد کانفیگ.
 */
class AccountsHandler
{
    public function __construct(
        protected Api $telegram,
        protected AccountService $accountService,
        protected WalletService $walletService,
        protected QrCodeGenerator $qr,
        protected IdentityService $identity,
    ) {}

    public function list(int $chatId, User $user): void
    {
        $accounts = $user->accounts()->with('product')->latest()->limit(10)->get();

        if ($accounts->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'شما هنوز هیچ اکانتی خریداری نکرده‌اید.']);

            return;
        }

        foreach ($accounts as $account) {
            $this->sendSummary($chatId, $account);
        }
    }

    protected function sendSummary(int $chatId, Account $account): void
    {
        $statusFa = match ($account->status) {
            'active' => $account->isExpired() ? '⚠️ منقضی‌شده' : '✅ فعال',
            'expired' => '⚠️ منقضی‌شده',
            'disabled' => '⛔️ غیرفعال',
            'suspended' => '⏸ تعلیق‌شده',
            default => $account->status,
        };

        $text = "🔹 {$account->product?->name}\n"
            .'نام اکانت: '.htmlspecialchars((string) $account->panel_username, ENT_QUOTES)."\n"
            ."وضعیت: {$statusFa}\n"
            .'تاریخ انقضا: '.$account->expires_at->format('Y-m-d')."\n";

        if ($account->traffic_gb !== null) {
            $text .= 'حجم کل: '.number_format((float) $account->traffic_gb, 2)." گیگ\n";
            $text .= 'حجم باقی‌مانده: '.number_format((float) $account->remainingTrafficGb(), 2)." گیگ\n";
        } else {
            $text .= "حجم کل: نامحدود\n";
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => Keyboards::accountActions($account->id),
        ]);
    }

    public function sendConfig(int $chatId, User $user, int $accountId): void
    {
        $account = $user->accounts()->findOrFail($accountId);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '🔗 لینک سابسکریپشن:'."\n".'<code>'.htmlspecialchars($this->qr->scannableTextFor($account), ENT_QUOTES).'</code>',
            'parse_mode' => 'HTML',
        ]);

        $this->telegram->sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::createFromContents($this->qr->pngFor($account), 'subscription.png'),
            'caption' => '📱 این QR را در اپلیکیشن VPN خود اسکن کنید تا سابسکریپشن به‌صورت خودکار اضافه شود.',
        ]);
    }

    /**
     * تمدید با همان مدت/حجم تعرفه‌ی فعلی و به قیمت فعلی همان محصول
     * (خرید حجم/مدت متفاوت در نسخه‌های بعدی اضافه می‌شود — بند ۱۰).
     *
     * نکته‌ی مهم: کسر کیف پول و تمدید روی پنل دو عملیات جدا هستند (برخلاف
     * AccountService::purchase که هر دو را در یک تراکنش انجام می‌دهد)،
     * چون renew() یک اکانت از قبل موجود را ویرایش می‌کند نه اکانت تازه
     * می‌سازد. اگر تمدید روی پنل شکست بخورد، باید مبلغ کسرشده را دستی
     * برگردانیم — وگرنه کاربر بدون دریافت خدمت، پول از دست داده است.
     */
    public function renew(int $chatId, User $user, int $accountId): void
    {
        $account = $user->accounts()->with('product')->findOrFail($accountId);
        $product = $account->product;

        // بریج backward-compatible (همان دلیل BuyAccountHandler و
        // WalletHandler): RenewalService از کیف‌پول CustomerAccount
        // فروشگاه اصلی کسر می‌کند، پس نمایش «قبل/بعد» هم باید از همان
        // کیف‌پول خوانده شود، نه از یک کیف‌پول جدای روی خودِ User که
        // اصلاً تغییر نمی‌کند.
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $balanceBeforeRenewal = $this->walletService->balance($customer);

        if ($balanceBeforeRenewal < $product->mainPrice()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: '.Money::format($product->mainPrice()),
            ]);

            return;
        }

        // ── فاز G: انتقال به هسته (بند ۴۱ بلوپرینت) ────────────────
        //
        // پیش از این، همین هندلر خودش کسر می‌کرد، بعد تمدید را صدا
        // می‌زد، و در صورت شکست خودش بازگشت وجه می‌داد — یعنی یک نسخه‌ی
        // محلی از منطق مالی. دقیقاً به همین دلیل بود که تا نسخه‌ی ۳.۰.۷
        // مسیر تمدید هنوز قیمت اشتباه را می‌گرفت درحالی‌که خرید اصلاح
        // شده بود.
        //
        // حالا همه‌ی این‌ها داخل RenewalService است: دروازه‌ها، کسر
        // اتمیک، جدایی مالی از پنل، ثبت Operation و صفر کردن حجم.
        try {
            app(RenewalService::class)->renew($account);
        } catch (InsufficientBalanceException) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'موجودی کیف پول کافی نیست.']);

            return;
        } catch (RenewalFailedException $e) {
            // پیام بر اساس سیاست فعلی ادمین (بازگشت فوری / تلاش مجدد / رسیدگی)
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "تمدید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}"]);

            return;
        } catch (\RuntimeException $e) {
            // نکته‌ی مهم: برخلاف قبل، اینجا بازگشت وجه دستی نمی‌دهیم.
            // RenewalService وضعیت سفارش را روی provision_failed
            // می‌گذارد که صریحاً یعنی «پول گرفته شده، سرویس تحویل نشده»
            // و قابل پیگیری/جبران توسط ادمین است. بازگشت خودکار در این
            // حالت خطرناک است، چون ممکن است تمدید روی پنل واقعاً انجام
            // شده و فقط پاسخش به ما نرسیده باشد.
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "تمدید ناموفق بود: {$e->getMessage()}\nلطفاً با پشتیبانی تماس بگیرید؛ وضعیت سفارش شما ثبت شده است.",
            ]);

            return;
        }

        // طبق درخواست صریح: موجودی کیف پول قبل و بعد از تمدید نمایش داده
        // شود — balanceBeforeRenewal همان مبلغی است که هنوز کسر نشده بود
        // (نه یک پیام جدا قبل از انجام عملیات)، و موجودی فعلی را دوباره
        // می‌خوانیم چون purchase() همین الان آن را تغییر داده است.
        $balanceAfterRenewal = $this->walletService->balance($customer);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ اکانت شما با موفقیت تمدید شد.\n\n"
                .'موجودی کیف پول قبل از تمدید: '.Money::format($balanceBeforeRenewal)."\n"
                .'موجودی کیف پول بعد از تمدید: '.Money::format($balanceAfterRenewal),
        ]);
        $this->sendSummary($chatId, $account->fresh());
    }
}
