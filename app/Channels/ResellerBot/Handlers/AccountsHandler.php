<?php

namespace App\Channels\ResellerBot\Handlers;

use App\Channels\ResellerBot\Support\Keyboards;
use App\Channels\TelegramBot\Support\QrCodeGenerator;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\ProductNotSellableException;
use App\Models\Account;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerPricingService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

/**
 * «📦 اکانت‌های من» در ربات نماینده. طبق «اصل طلایی مشتری نماینده»
 * (سند نیازمندی، بند ۲)، فقط اکانت‌هایی که هم مال همین کاربر و هم از
 * طریق همین نماینده خریداری شده‌اند نمایش داده می‌شوند —
 * Account::scopeOfReseller (روی order.reseller_id، نه user.reseller_id)
 * دقیقاً همین را تضمین می‌کند.
 */
class AccountsHandler
{
    public function __construct(
        protected Api $telegram,
        protected AccountService $accountService,
        protected WalletService $walletService,
        protected QrCodeGenerator $qr,
        protected ResellerPricingService $pricing,
    ) {}

    public function list(Reseller $reseller, int $chatId, User $user): void
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->ofReseller($reseller->id)
            ->with('product')
            ->latest()
            ->limit(10)
            ->get();

        if ($accounts->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'شما هنوز هیچ اکانتی از این فروشگاه خریداری نکرده‌اید.']);

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

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => Keyboards::accountActions($account->id),
        ]);
    }

    public function sendConfig(Reseller $reseller, int $chatId, User $user, int $accountId): void
    {
        $account = Account::query()->where('user_id', $user->id)->ofReseller($reseller->id)->findOrFail($accountId);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '🔗 لینک سابسکریپشن:'."\n".'<code>'.htmlspecialchars($this->qr->scannableTextFor($account), ENT_QUOTES).'</code>',
            'parse_mode' => 'HTML',
        ]);

        $this->telegram->sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::createFromContents($this->qr->pngFor($account), 'subscription.png'),
            'caption' => '📱 این QR را در اپلیکیشن VPN خود اسکن کنید.',
        ]);
    }

    /**
     * تمدید هم طبق «Renewal Contract» (بند ۹ سند Spec) همان قرارداد
     * مالیِ خریدِ اولیه را دارد: کیف‌پول مشتری با قیمتِ فروشِ نماینده،
     * و اعتبار نماینده هم‌زمان با قیمتِ پایه کسر می‌شود.
     */
    public function renew(Reseller $reseller, int $chatId, User $user, int $accountId): void
    {
        $account = Account::query()->where('user_id', $user->id)->ofReseller($reseller->id)->with('product')->findOrFail($accountId);
        $product = $account->product;

        // دروازه‌ی مرکزی sellability — تمدید هم دقیقاً مثل خرید باید از
        // آن عبور کند (P0 گزارش امنیتی). بدون این، سبد فروشِ بسته‌شده یا
        // نمایندگیِ غیرفعال همچنان می‌توانست از مسیر تمدید ادامه دهد.
        try {
            $this->pricing->assertSellable($reseller, $product);
        } catch (ProductNotSellableException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'این محصول دیگر برای تمدید در دسترس نیست.']);

            return;
        }

        // این‌ها فقط برای پیام‌دادن زودهنگام به کاربرند؛ مرجع واقعی
        // همچنان RenewalService/PurchaseGuard است.
        $customersPrice = $product->customersPrice($reseller);
        $resellerPrice = $product->resellerPrice();

        if ($this->walletService->balanceIn($user, StoreContext::reseller($reseller)) < $customersPrice) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: '.number_format($customersPrice).' تومان']);

            return;
        }

        if ($this->walletService->balance($reseller) < $resellerPrice) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'تمدید در حال حاضر ممکن نیست. لطفاً با پشتیبانی تماس بگیرید.']);

            return;
        }

        // هر دو کسر در یک تراکنش واحد (P0): پیش از این دو فراخوانی
        // مستقل بودند، پس اگر کسر از مشتری موفق و کسر از نماینده ناموفق
        // می‌شد، پول مشتری رفته بود بدون اینکه تمدیدی انجام شود و بدون
        // اینکه هیچ بازگشتی اجرا شود (چون به بلوک catchِ تمدید نمی‌رسید).
        // ── فاز G: انتقال به هسته (بند ۴۲ بلوپرینت) ────────────────
        //
        // کل بلوک قبلی — کسر دوطرفه، تمدید، و بازگشت دستی در صورت شکست —
        // یک نسخه‌ی محلی از منطق مالی بود که باید با هر تغییر قانون
        // جداگانه به‌روز می‌شد. حالا RenewalService همان کار را انجام
        // می‌دهد، با این تفاوت‌ها: سقف بدهی نماینده هم اعمال می‌شود،
        // قیمت اسنپ‌شات می‌گیرد، حجم صفر می‌شود، و نتیجه روی Operation
        // ثبت می‌شود.
        try {
            app(RenewalService::class)->renew($account);
        } catch (InsufficientBalanceException) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'موجودی کافی نیست.']);

            return;
        } catch (RenewalFailedException $e) {
            // پیام بر اساس سیاست فعلی ادمین (بازگشت فوری / تلاش مجدد / رسیدگی)
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "تمدید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}"]);

            return;
        } catch (\RuntimeException $e) {
            // بازگشت خودکار عمداً حذف شد: سفارش در وضعیت
            // provision_failed ثبت می‌شود که صریحاً یعنی «پول گرفته شده،
            // سرویس تحویل نشده». اگر تمدید روی پنل واقعاً انجام شده و
            // فقط پاسخش نرسیده باشد، بازگشت خودکار یعنی هم سرویس
            // داده‌ایم هم پول برگردانده‌ایم.
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "تمدید ناموفق بود: {$e->getMessage()}\nلطفاً با پشتیبانی تماس بگیرید؛ وضعیت سفارش ثبت شده است.",
            ]);

            return;
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ اکانت شما با موفقیت تمدید شد.\n\n".'موجودی کیف پول: '.number_format($this->walletService->balanceIn($user, StoreContext::reseller($reseller))).' تومان',
        ]);
        $this->sendSummary($chatId, $account->fresh());
    }
}
