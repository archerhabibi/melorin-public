<?php

namespace App\Channels\TelegramBot\Handlers;

use App\Support\Money;
use App\Channels\TelegramBot\Support\ConversationState;
use App\Channels\TelegramBot\Support\Keyboards;
use App\Channels\TelegramBot\Support\QrCodeGenerator;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

/**
 * جریان «🛒 خرید اکانت» — دقیقاً مطابق نمودار بند ۳۳ سند نیازمندی:
 * انتخاب دسته‌بندی → انتخاب محصول → انتخاب سرور (دستی طبق بند ۶.۱ اگر
 * دسته‌بندی روی manual تنظیم شده باشد، وگرنه خودکار طبق بند ۶.۲) →
 * پرداخت از کیف پول → ساخت اکانت → تحویل کانفیگ.
 *
 * توجه: خود این هندلر هرگز اکانتی نمی‌سازد یا کیف پولی را کم نمی‌کند —
 * تمام این کار را AccountService (که خودش WalletService را صدا می‌زند)
 * انجام می‌دهد؛ اینجا فقط UI/Adapter است (بند ۳۴).
 */
class BuyAccountHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected AccountService $accountService,
        protected WalletService $walletService,
        protected QrCodeGenerator $qr,
        protected IdentityService $identity,
    ) {}

    /**
     * بریج backward-compatible: موجودیِ واقعی‌ای که خرید/تمدید از آن کسر
     * می‌کنند، کیف‌پولِ CustomerAccountِ فروشگاه اصلی است، نه کیف‌پولی
     * که مستقیماً روی User باشد (همان دلیلِ PaymentService::resolveWalletOwner
     * و WalletHandler::showBalance). این هندلر چند جا فقط برای *نمایش*
     * یا یک چکِ مشورتیِ اولیه به موجودی نیاز دارد؛ آن‌ها را از این متد
     * می‌خوانیم تا با چیزی که AccountService واقعاً کسر می‌کند یکی باشد.
     */
    protected function mainWalletBalance(User $user): int
    {
        return $this->walletService->balance(
            $this->identity->resolveCustomerAccount($user, StoreContext::main())
        );
    }

    public function start(int $chatId, User $user): void
    {
        $categories = Category::query()->where('status', 'active')->get();

        if ($categories->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'در حال حاضر هیچ سبد فروش فعالی موجود نیست.']);

            return;
        }

        $this->state->set($chatId, ConversationState::BUY_CHOOSE_CATEGORY, [], $user);

        // درخواست واقعی: قبل از لیست سبدهای فروش، اطلاعات حساب خریدار
        // (که واقعاً دارد از آن حساب خرید می‌کند) نمایش داده شود — هم
        // برای اطمینان خود کاربر که با حساب درست وارد شده، هم برای
        // تشخیص سریع‌تر مشکلات مشابه (کسر از حساب اشتباه) در آینده.
        $accountInfo = "👤 {$user->full_name}\n"
            ."شناسه‌ی عددی تلگرام: {$user->telegram_id}\n"
            .'💰 موجودی کیف پول: '.Money::format($this->mainWalletBalance($user))."\n";

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $accountInfo."\nیک سبد فروش انتخاب کنید:",
            'reply_markup' => Keyboards::categoryList($categories),
        ]);
    }

    public function showProducts(int $chatId, User $user, int $categoryId): void
    {
        $category = Category::query()->where('status', 'active')->findOrFail($categoryId);
        $products = $category->products()->where('status', 'active')->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'برای این سبد فروش تعرفه‌ی فعالی تعریف نشده است.']);

            return;
        }

        $this->state->set($chatId, ConversationState::BUY_CHOOSE_PRODUCT, ['category_id' => $categoryId], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "تعرفه‌های «{$category->name}»:",
            'reply_markup' => Keyboards::productList($products),
        ]);
    }

    /**
     * وقتی سبد فروشِ محصول در حالت انتخاب دستی سرور است (بند ۶.۱)، قبل
     * از خرید، لیست سرورهای مجاز آن دسته‌بندی نمایش داده می‌شود تا کاربر
     * خودش انتخاب کند از کدام سرور اکانت بگیرد؛ در غیر این صورت مستقیم
     * سراغ proceedAfterServer() (که خودش تصمیم می‌گیرد نام دلخواه بپرسد
     * یا مستقیم بخرد) می‌رود.
     */
    public function chooseServerOrPurchase(int $chatId, User $user, int $productId): void
    {
        $product = Product::query()->with('category')->where('status', 'active')->findOrFail($productId);

        if ($product->category->server_selection_mode !== 'manual') {
            $this->proceedAfterServer($chatId, $user, $productId, null);

            return;
        }

        $panels = $product->category->serverPanels()->where('status', 'active')->get();

        if ($panels->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'در حال حاضر سرور فعالی برای این سبد فروش موجود نیست.']);

            return;
        }

        $this->state->set($chatId, ConversationState::BUY_CHOOSE_SERVER, ['product_id' => $productId], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'سرور موردنظر خود را انتخاب کنید:',
            'reply_markup' => Keyboards::serverList($panels, $productId),
        ]);
    }

    /**
     * نقطه‌ی مشترکی که چه بعد از انتخاب خودکار سرور (بدون هیچ قدم اضافه)
     * و چه بعد از انتخاب دستی سرور (بعد از callback «buy:server») به آن
     * می‌رسیم. اگر سبد فروش روی نام‌گذاری دلخواه تنظیم شده باشد، همین‌جا
     * نام را از کاربر می‌پرسد؛ وگرنه مستقیم می‌رود سراغ purchase().
     */
    public function proceedAfterServer(int $chatId, User $user, int $productId, ?int $panelId): void
    {
        $product = Product::query()->with('category')->where('status', 'active')->findOrFail($productId);

        if ($product->category->naming_mode !== 'custom') {
            $this->purchase($chatId, $user, $productId, $panelId);

            return;
        }

        $this->state->set($chatId, ConversationState::BUY_AWAITING_CUSTOM_NAME, [
            'product_id' => $productId,
            'panel_id' => $panelId,
        ], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✏️ یک نام دلخواه برای اکانت خود بفرستید:\n\nفقط حروف انگلیسی، عدد و _ مجاز است؛ باید با یک حرف شروع شود و بین ۳ تا ۲۰ کاراکتر باشد (مثال: myaccount1).",
        ]);
    }

    /**
     * ادامه‌ی جریان بعد از این‌که کاربر در پاسخ به proceedAfterServer()
     * یک پیام متنی به‌عنوان نام دلخواه فرستاده. اعتبارسنجی فرمت اینجا،
     * و یکتاسازیِ نهایی (افزودن عدد ترتیبی در صورت تکراری بودن) داخل
     * AccountService::generateUsername انجام می‌شود.
     */
    public function handleCustomNameText(int $chatId, User $user, string $text): void
    {
        $state = $this->state->find($chatId);
        $productId = $state->payload['product_id'] ?? null;
        $panelId = $state->payload['panel_id'] ?? null;

        if (! $productId) {
            $this->state->reset($chatId);
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'جریان خرید منقضی شده. لطفاً دوباره از ابتدا شروع کنید.']);

            return;
        }

        $name = trim($text);

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{2,19}$/', $name)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "نام نامعتبر است.\nفقط حروف انگلیسی، عدد و _ مجاز است؛ باید با حرف شروع شود و بین ۳ تا ۲۰ کاراکتر باشد.\nدوباره ارسال کنید:",
            ]);

            return; // در همین state می‌مانیم تا کاربر دوباره تلاش کند
        }

        $this->purchase($chatId, $user, (int) $productId, $panelId ? (int) $panelId : null, $name);
    }

    /**
     * تلاش برای خرید. طبق بند ۹، پرداخت باید تأیید شده باشد؛ در نسخه‌ی
     * ربات، «تأیید پرداخت» معادل کافی‌بودن موجودی کیف پول است — کیف پول
     * باید از قبل (بخش 💰 کیف پول) شارژ شده باشد.
     *
     * $panelId فقط وقتی پر است که کاربر از chooseServerOrPurchase() یک
     * سرور مشخص انتخاب کرده باشد (حالت دستی)؛ در غیر این صورت null است و
     * AccountService خودش طبق استراتژی پیش‌فرض یک سرور را انتخاب می‌کند.
     * $customUsername فقط وقتی پر است که سبد فروش روی نام‌گذاری دلخواه
     * تنظیم شده و کاربر از handleCustomNameText() یک نام معتبر فرستاده.
     */
    public function purchase(int $chatId, User $user, int $productId, ?int $panelId = null, ?string $customUsername = null): void
    {
        $product = Product::query()->where('status', 'active')->findOrFail($productId);
        $manualPanel = $panelId ? ServerPanel::query()->where('status', 'active')->find($panelId) : null;

        if ($panelId && ! $manualPanel) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'این سرور دیگر در دسترس نیست. دوباره از ابتدا تلاش کنید.']);

            return;
        }

        if ($this->mainWalletBalance($user) < $product->mainPrice()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "موجودی کیف پول شما کافی نیست.\nقیمت این تعرفه: ".Money::format($product->mainPrice())."\nموجودی فعلی: ".Money::format($this->mainWalletBalance($user))."\n\nابتدا از بخش «💰 کیف پول و شارژ حساب» حساب خود را شارژ کنید.",
            ]);

            return;
        }

        try {
            $account = $this->accountService->purchase($user, $product, manualPanel: $manualPanel, salesChannel: 'main_bot', customUsername: $customUsername);
        } catch (InsufficientBalanceException) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'موجودی کیف پول کافی نیست.']);

            return;
        } catch (ProvisioningFailedException $e) {
            // پیام بر اساس سیاست فعلی ادمین (بازگشت فوری / تلاش مجدد / رسیدگی)
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "خرید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}"]);

            return;
        } catch (\RuntimeException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "خرید ناموفق بود: {$e->getMessage()}\nدر صورت کسر وجه، سفارش ثبت شده و پشتیبانی پیگیری می‌کند."]);

            return;
        }

        $this->state->reset($chatId);
        $this->deliverConfig($chatId, $account);

        // طبق درخواست صریح: بعد از اتمام مراحل خرید در ربات، موجودی کیف
        // پول نمایش داده شود. عمداً به‌جای اضافه‌کردن این به داخل
        // deliverConfig() یک پیام جدا فرستاده می‌شود — deliverConfig()
        // برای تحویل اکانتِ تست هم reuse می‌شود (MiscHandler::testAccount)
        // که آنجا نمایش موجودی معنا ندارد (رایگان است) و نباید تغییر کند.
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی کیف پول شما: '.Money::format($this->mainWalletBalance($user)),
        ]);
    }

    public function deliverConfig(int $chatId, Account $account): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "✅ اکانت شما با موفقیت ساخته شد.\n\n"
                .'نام کاربری: '.htmlspecialchars((string) $account->panel_username, ENT_QUOTES)."\n"
                .'تاریخ انقضا: '.$account->expires_at->format('Y-m-d')."\n"
                .($account->traffic_gb ? "حجم: {$account->traffic_gb} گیگابایت\n" : '')
                ."\n🔗 لینک سابسکریپشن:\n".'<code>'.htmlspecialchars($this->qr->scannableTextFor($account), ENT_QUOTES).'</code>'
                ."\n\nاین لینک را در اپلیکیشن VPN خود به‌عنوان سابسکریپشن اضافه کنید (نه یک کانفیگ تکی) تا با هر تغییر بعدی خودکار به‌روز بماند.",
            'parse_mode' => 'HTML',
        ]);

        $this->telegram->sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::createFromContents($this->qr->pngFor($account), 'subscription.png'),
            'caption' => '📱 یا این QR را در اپلیکیشن VPN خود اسکن کنید تا سابسکریپشن به‌صورت خودکار اضافه شود.',
        ]);
    }
}
