<?php

namespace App\Channels\ResellerBot\Handlers;

use App\Channels\ResellerBot\Support\ConversationState;
use App\Channels\ResellerBot\Support\Keyboards;
use App\Channels\TelegramBot\Support\QrCodeGenerator;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\ResellerScopeViolationException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\WalletService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Resellers\ResellerPricingService;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

/**
 * جریان «🛒 خرید اکانت» در ربات نماینده. برخلاف ربات اصلی، اینجا:
 * ۱) فقط محصولاتی که خودِ نماینده فعال/قیمت‌گذاری کرده نشان داده می‌شوند
 *    (ResellerPricingService::sellableProducts — مدل opt-in)،
 * ۲) خرید طبق «Double-Debit» است: کیف‌پول مشتری با قیمتِ فروشِ نماینده،
 *    و اعتبار نماینده هم‌زمان با قیمتِ پایه کسر می‌شود — هر دو داخل
 *    AccountService::purchase()، نه اینجا (بند ۳۴: هندلر فقط Adapter است).
 */
class BuyAccountHandler
{
    public function __construct(
        protected Api $telegram,
        protected ConversationState $state,
        protected AccountService $accountService,
        protected WalletService $walletService,
        protected ResellerPricingService $pricing,
        protected QrCodeGenerator $qr,
        protected IdentityService $identity,
    ) {}

    public function start(Reseller $reseller, int $chatId, User $user): void
    {
        $sellableProductIds = $this->pricing->sellableProducts($reseller)->pluck('category_id')->unique();
        $categories = Category::query()->where('status', 'active')->whereIn('id', $sellableProductIds)->get();

        if ($categories->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'در حال حاضر هیچ محصولی برای فروش تنظیم نشده است.']);

            return;
        }

        $this->state->set($reseller, $chatId, ConversationState::BUY_CHOOSE_CATEGORY, [], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی کیف پول شما: '.number_format($this->walletService->balance($user))."تومان\n\nیک سبد فروش انتخاب کنید:",
            'reply_markup' => Keyboards::categoryList($categories),
        ]);
    }

    public function showProducts(Reseller $reseller, int $chatId, User $user, int $categoryId): void
    {
        $category = Category::query()->where('status', 'active')->findOrFail($categoryId);
        $sellable = $this->pricing->sellableProducts($reseller)->where('category_id', $category->id);

        if ($sellable->isEmpty()) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'برای این سبد فروش محصولی تنظیم نشده است.']);

            return;
        }

        $rows = $sellable->map(fn (Product $p) => [$p, $p->customersPrice($reseller)])->values();

        $this->state->set($reseller, $chatId, ConversationState::BUY_CHOOSE_PRODUCT, ['category_id' => $categoryId], $user);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "تعرفه‌های «{$category->name}»:",
            'reply_markup' => Keyboards::productList($rows),
        ]);
    }

    public function purchase(Reseller $reseller, int $chatId, User $user, int $productId): void
    {
        $customer = $this->identity->resolveCustomerAccount(
            $user,
            StoreContext::fromReseller($reseller),
        );
        $product = Product::query()->where('status', 'active')->findOrFail($productId);
        // customers_price — قیمتی که همین نماینده برای همین محصول
        // تعیین کرده (بند ۶). null یعنی اصلاً قابل‌فروش نیست.
        $customersPrice = $product->customersPrice($reseller);

        if ($customersPrice === null) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'این محصول دیگر در دسترس نیست.']);

            return;
        }

        if ($this->walletService->balance($customer) < $customersPrice) {
        $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'موجودی کیف پول شما کافی نیست.'
                    ."\nقیمت این تعرفه: ".number_format($customersPrice).' تومان'
                    ."\nموجودی فعلی: ".number_format($this->walletService->balance($customer)).' تومان'
                    ."\n\nابتدا از بخش «💰 شارژ حساب» حساب خود را شارژ کنید.",
            ]);

            return;
        }

        try {
            $account = $this->accountService->purchase($user, $product, salesChannel: 'reseller_bot', reseller: $reseller);
        } catch (InsufficientBalanceException) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => 'موجودی کافی نیست (کیف‌پول شما یا اعتبار نماینده).']);

            return;
        } catch (ResellerScopeViolationException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => $e->getMessage()]);

            return;
        } catch (ProvisioningFailedException $e) {
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "خرید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}"]);

            return;
        } catch (\RuntimeException $e) {
            // بدون وعده‌ی بازگشت خودکار: اگر کسر انجام شده باشد، سفارش
            // در وضعیت provision_failed ثبت شده و رسیدگی می‌شود.
            $this->telegram->sendMessage(['chat_id' => $chatId, 'text' => "خرید ناموفق بود: {$e->getMessage()}\nدر صورت کسر وجه، سفارش ثبت شده و پشتیبانی پیگیری می‌کند."]);

            return;
        }

        $this->state->reset($reseller, $chatId);
        $this->deliverConfig($chatId, $account);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '💰 موجودی کیف پول شما: '.number_format($this->walletService->balance($customer)).' تومان',
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
                ."\n🔗 لینک سابسکریپشن:\n".'<code>'.htmlspecialchars($this->qr->scannableTextFor($account), ENT_QUOTES).'</code>',
            'parse_mode' => 'HTML',
        ]);

        $this->telegram->sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::createFromContents($this->qr->pngFor($account), 'subscription.png'),
            'caption' => '📱 این QR را در اپلیکیشن VPN خود اسکن کنید.',
        ]);
    }
}
