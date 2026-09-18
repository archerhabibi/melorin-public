<?php

namespace App\Services\Core;

use App\DataTransferObjects\PanelAccountRequest;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\Panels\SupportsUsernameAvailability;
use App\Services\Core\ServerSelection\ServerSelectionStrategy;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AccountService — پیاده‌سازی جریان کامل «خرید و ساخت اکانت» (بند ۹ سند
 * نیازمندی). این تنها نقطه‌ی مجاز برای ساخت اکانت در کل سیستم است؛ طبق
 * اصل معماری بند ۳۴، هیچ کانال فروش (ربات، سایت، نماینده) مجاز نیست این
 * منطق را مستقیم پیاده‌سازی کند.
 */
class AccountService
{
    public function __construct(
        protected WalletService $walletService,
        protected ServerSelectionStrategy $serverSelection,
        protected ResellerPricingService $resellerPricing,
        protected IdentityService $identity,
        protected PurchaseService $purchaseService,
    ) {}

    /**
     * جریان کامل خرید:
     * کاربر → انتخاب محصول → کسر از کیف پول → انتخاب سرور →
     * ساخت اکانت روی پنل → ثبت سفارش و اکانت.
     *
     * $isTest=true برای مسیر «اکانت تست» است: چون قیمت محصول تست همیشه
     * صفر است و WalletService::purchase() برای مبلغ صفر/منفی خطا می‌دهد
     * (assertPositive)، در این حالت اصلاً کیف‌پول صدا زده نمی‌شود — ولی
     * بقیه‌ی مسیر (انتخاب سرور، تولید نام کاربری طبق naming_mode، ساخت
     * اکانت روی پنل، ثبت Order/Account) دقیقاً همان مسیر خرید واقعی
     * است، فقط با is_test=true روی رکورد Account.
     *
     * @throws InsufficientBalanceException
     * @throws \RuntimeException در صورت شکست ساخت اکانت روی پنل
     */
    public function purchase(
        User $user,
        Product $product,
        ?ServerPanel $manualPanel = null,
        string $salesChannel = 'main_bot',
        ?Reseller $reseller = null,
        ?string $customUsername = null,
        bool $isTest = false,
        ?int $testTrafficMb = null,
        ?int $testDurationHours = null,
        ?string $idempotencyKey = null,
    ): Account {
        // ── فاز G: پل به هسته‌ی جدید ───────────────────────────────
        //
        // بند ۴۱ بلوپرینت: «بازنویسی کامل Bot ممنوع؛ فقط Migration به
        // Core Service». به‌جای دست‌زدن به ده‌ها هندلر ربات، خودِ این متد
        // به PurchaseService جدید واگذار می‌کند. نتیجه این است که تمام
        // مسیرهای موجود (ربات اصلی، ربات نماینده، پنل) بدون یک خط
        // تغییر، خودبه‌خود از مزایای فازهای B تا F بهره‌مند می‌شوند:
        // دروازه‌های خرید، اسنپ‌شات قیمت، سقف بدهی، جدایی مالی از
        // Provisioning، Idempotency، و کمیسیون/پاداش.
        //
        // استثنا: مسیر «اکانت تست» همچنان از پیاده‌سازی قدیمی استفاده
        // می‌کند، چون حجم و مدتش مستقل از خودِ محصول تعیین می‌شود و
        // PurchaseService (که عمداً فقط خرید واقعی را مدل می‌کند) چنین
        // مفهومی ندارد. اضافه‌کردن آن به هسته یعنی آلوده‌کردن مسیر مالی
        // با یک حالت خاصِ بی‌ربط به پول.
        if (! $isTest) {
            $store = StoreContext::fromReseller($reseller);
            $customer = $this->identity->resolveCustomerAccount($user, $store);

            return $this->purchaseService->purchase(
                customer: $customer,
                product: $product,
                store: $store,
                salesChannel: $salesChannel,
                manualPanel: $manualPanel,
                customUsername: $customUsername,
                idempotencyKey: $idempotencyKey,
            );
        }

        return $this->legacyTestAccountPurchase(
            $user, $product, $manualPanel, $salesChannel, $customUsername, $testTrafficMb, $testDurationHours
        );
    }

    /**
     * مسیر قدیمی، حالا فقط برای اکانت تست.
     *
     * عمداً نگه داشته شده و حذف نشده: بند ۷۱ بلوپرینت می‌گوید پیش از
     * تغییر AccountService باید تمام call-siteها پیدا شوند. تا وقتی
     * مسیر تست هم به یک سرویس اختصاصی منتقل نشده، این کد زنده می‌ماند.
     */
    protected function legacyTestAccountPurchase(
        User $user,
        Product $product,
        ?ServerPanel $manualPanel,
        string $salesChannel,
        ?string $customUsername,
        ?int $testTrafficMb,
        ?int $testDurationHours,
    ): Account {
        return DB::transaction(function () use ($user, $product, $manualPanel, $salesChannel, $customUsername, $testTrafficMb, $testDurationHours) {

            // این مسیر فقط برای اکانت تست است: هیچ Context نمایندگی،
            // هیچ قیمتی و هیچ کسری در کار نیست. تمام منطق مالی — هر سه
            // قیمت سند و Double-Debit — منحصراً در PurchaseService
            // زندگی می‌کند (بند ۱۲ و ۱۳).
            $isTest = true;

            // ۱. انتخاب سرور — دستی یا خودکار بسته به تنظیمات دسته‌بندی (بند ۶)
            $panel = $manualPanel ?? $this->serverSelection->select($product->category);

            if (! $panel) {
                throw new \RuntimeException('هیچ سرور فعالی برای این دسته‌بندی در دسترس نیست.');
            }

            // ۲. ثبت سفارش با وضعیت pending
            $order = Order::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'reseller_id' => null,
                'sales_channel' => $salesChannel,
                'base_price' => 0,
                'core_price' => 0,
                'sold_price' => 0,
                'status' => 'pending',
            ]);

            // ۳. هیچ کسری: اکانت تست همیشه رایگان است.
            $order->update(['status' => 'paid']);

            // برای اکانت تست، حجم/مدت را (اگر ادمین در تنظیمات اکانت تست
            // مقداردهی کرده باشد) مستقل از traffic_gb/duration_days خودِ
            // محصول محاسبه می‌کنیم — محصول فقط دسته‌بندی/پروتکل/سرورهای
            // مجاز را تعیین می‌کند، طبق بندهای «حجم تست» و «مدت اعتبار»
            // در سند نیازمندی.
            $trafficBytes = ($isTest && $testTrafficMb !== null)
                ? $testTrafficMb * 1024 ** 2
                : ($product->traffic_gb ? (int) ($product->traffic_gb * 1024 ** 3) : 0);

            $expiresAt = ($isTest && $testDurationHours !== null)
                ? now()->addHours($testDurationHours)
                : now()->addDays($product->duration_days);

            $accountTrafficGb = ($isTest && $testTrafficMb !== null)
                ? round($testTrafficMb / 1024, 4)
                : $product->traffic_gb;

            // ۴. ساخت اکانت روی پنل واقعی
            // uuid را همین‌جا (نه داخل درایور) تولید می‌کنیم تا صرف‌نظر از
            // نوع پنل، از همان ابتدا در اختیار AccountService باشد و بتوان
            // آن را برای عملیات بعدی (تمدید/حذف روی پنل‌هایی مثل Sanaei که
            // با uuid کلاینت کار می‌کنند، نه فقط username) ذخیره کرد.
            $username = $this->generateUsername($product, $panel, $customUsername, $isTest);
            $clientUuid = (string) Str::uuid();
            // subId فقط برای پنل‌هایی مثل سنایی معنا دارد (شناسه‌ی
            // سرویس Subscription)، ولی چون تولیدش هیچ وابستگی به نوع پنل
            // ندارد، اینجا و نه داخل درایور تولید می‌شود — دقیقاً همان
            // دلیلی که uuid کلاینت هم اینجا تولید می‌شود.
            $subId = Str::random(16);

            $panelRequest = new PanelAccountRequest(
                username: $username,
                trafficBytes: $trafficBytes,
                expireTimestamp: $expiresAt->timestamp,
                note: "melorin-order-{$order->id}",
                extra: array_merge(
                    $panel->extra_settings ?? [],
                    ['uuid' => $clientUuid, 'tg_id' => $user->telegram_id, 'sub_id' => $subId]
                ),
            );

            $driver = PanelDriverFactory::make($panel->panel_type);
            $result = $driver->createAccount($panel, $panelRequest);

            if (! $result->success) {
                // اکانت تست رایگان است، پس چیزی برای بازگشت وجود ندارد.
                $order->update(['status' => 'failed']);

                throw new \RuntimeException("ساخت اکانت روی پنل ناموفق بود: {$result->errorMessage}");
            }

            // ۵. ثبت اکانت در دیتابیس مرکزی
            //
            // طبق درخواست صریح: چیزی که به کاربر تحویل داده می‌شود باید
            // لینک سابسکریپشن باشد (subscription_url)، نه کانفیگ خام تک‌
            // پروتکلی — چون کلاینت‌های VPN از روی لینک سابسکریپشن خودشان
            // را به‌روز نگه می‌دارند و با تغییر بعدیِ حجم/انقضا نیازی به
            // تحویل دوباره‌ی کانفیگ به کاربر نیست. rawResponse فقط برای
            // اشکال‌زدایی/سوابق نگه داشته می‌شود، نه برای نمایش به کاربر.
            //
            // جبران‌سازی Orphan Account (P2 گزارش امنیتی، مورد #12):
            // اکانت همین الان روی پنل ساخته شده، ولی یک تراکنش دیتابیس
            // نمی‌تواند یک فراخوانی API خارجی را rollback کند. اگر از
            // این نقطه به بعد چیزی شکست بخورد، rollback فقط ردیف‌های
            // دیتابیس را برمی‌گرداند و اکانت روی پنل بدون هیچ رکوردی در
            // ملورین باقی می‌ماند — یعنی ظرفیت و ترافیک مصرف می‌کند
            // بدون اینکه به کسی فروخته شده باشد یا قابل مدیریت باشد.
            // پس خودمان صریحاً پاکش می‌کنیم و بعد خطا را بالا می‌دهیم.
            try {
                $account = Account::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'server_panel_id' => $panel->id,
                    'protocol_id' => $product->protocol_id,
                    'panel_username' => $username,
                    'panel_client_uuid' => $clientUuid,
                    'subscription_id' => $result->panelExtra['subscription_id'] ?? null,
                    'subscription_url' => $result->subscriptionUrl,
                    'config_data' => json_encode(['raw' => $result->rawResponse]),
                    'starts_at' => now(),
                    'expires_at' => $expiresAt,
                    'traffic_gb' => $accountTrafficGb,
                    'status' => 'active',
                    'is_test' => $isTest,
                ]);

                $order->update(['status' => 'account_created']);
                $panel->increment('active_accounts_count');
            } catch (\Throwable $e) {
                try {
                    $driver->deleteAccount($panel, $username);
                    Log::warning('orphan_account_compensated', [
                        'panel_id' => $panel->id,
                        'panel_username' => $username,
                        'reason' => $e->getMessage(),
                    ]);
                } catch (\Throwable $cleanupError) {
                    // اگر خودِ پاک‌سازی هم شکست بخورد، دیگر کاری از دست
                    // کد برنمی‌آید — ولی این دقیقاً موردی است که باید
                    // دستی پیگیری شود، پس با شدت بالاتر لاگ می‌شود.
                    Log::critical('orphan_account_cleanup_failed', [
                        'panel_id' => $panel->id,
                        'panel_username' => $username,
                        'original_error' => $e->getMessage(),
                        'cleanup_error' => $cleanupError->getMessage(),
                    ]);
                }

                throw $e;
            }

            return $account;
        });
    }

    /**
     * تمدید اکانت موجود (بند ۱۰ سند نیازمندی، بند ۲۸ بلوپرینت).
     *
     * مسیر مالیِ تمدید در RenewalService است؛ این متد فقط بخش پنل را
     * انجام می‌دهد و برای ابزارهای مدیریتی/اسکریپت‌ها نگه داشته شده.
     *
     * قانون تمدید: **هم زمان و هم ترافیک** ریست/تمدید می‌شوند.
     * تمدیدی که فقط تاریخ را جلو ببرد از دید کاربر اصلاً تمدید نیست —
     * اکانتی که حجمش تمام شده با تاریخ جدید هم کار نمی‌کند.
     */
    public function renew(Account $account, int $additionalDays, ?int $newTrafficGb = null): Account
    {
        $panel = $account->serverPanel;
        $driver = PanelDriverFactory::make($panel->panel_type);

        // تمدید زودهنگام روزهای باقی‌مانده را نمی‌سوزاند.
        $newExpiry = $account->expires_at->isPast()
            ? now()->addDays($additionalDays)
            : $account->expires_at->copy()->addDays($additionalDays);

        // سقف ترافیک ریست می‌شود (نه جمع‌زده): مقدار داده‌شده، یا اگر
        // داده نشده همان سقف فعلیِ اکانت — که پس از resetUsage دوباره
        // کامل در اختیار کاربر است.
        $newTraffic = $newTrafficGb !== null
            ? (float) $newTrafficGb
            : (float) $account->traffic_gb;

        // از همان شناسه‌ای که موقع ساخت اکانت روی پنل ذخیره شده استفاده می‌کنیم
        // (نه telegram_id یا id داخلی ما که هیچ‌ربطی به شناسه‌ی پنل ندارند).
        $result = $driver->updateAccount($panel, $account->panel_username, new PanelAccountRequest(
            username: $account->panel_username,
            trafficBytes: $newTraffic ? (int) ($newTraffic * 1024 ** 3) : 0,
            expireTimestamp: $newExpiry->timestamp,
            extra: array_merge($panel->extra_settings ?? [], [
                'uuid' => $account->panel_client_uuid,
                'sub_id' => $account->subscription_id,
            ]),
        ));

        if (! $result->success) {
            throw new \RuntimeException("تمدید اکانت ناموفق بود: {$result->errorMessage}");
        }

        // ریست مصرف روی پنل — بدون این، سقف جدید بی‌اثر است.
        try {
            $driver->resetUsage($panel, $account->panel_username);
        } catch (\Throwable $e) {
            Log::warning('renewal_traffic_reset_failed', [
                'account_id' => $account->id,
                'panel_id' => $panel->id,
                'error' => $e->getMessage(),
            ]);
        }

        $account->update([
            'expires_at' => $newExpiry,
            'traffic_gb' => $newTraffic,
            'traffic_used_gb' => 0,
            'status' => 'active',
            // اگر قبلاً (اکانت‌های قدیمی‌تر) لینک سابسکریپشن ذخیره نشده
            // بود، همین‌جا از روی نتیجه‌ی تمدید دوباره پر می‌شود.
            'subscription_id' => $account->subscription_id ?? ($result->panelExtra['subscription_id'] ?? null),
            'subscription_url' => $account->subscription_url ?? $result->subscriptionUrl,
        ]);

        return $account;
    }

    /**
     * طبق درخواست صریح، تولید نام کاربری اکانت به naming_mode سبد فروش
     * بستگی دارد:
     *
     * - custom: از نامی که کاربر در ربات وارد کرده استفاده می‌شود؛ اگر
     *   آن نام از قبل روی جدول accounts موجود بود، عدد ترتیبی (۱، ۲، ...)
     *   به انتهایش اضافه می‌شود تا یکتا شود. اگر سبد فروش روی custom
     *   تنظیم شده ولی به هر دلیلی نامی نرسیده باشد (مثلاً فراخوانی مستقیم
     *   AccountService بدون عبور از جریان ربات)، برای جلوگیری از خطا به
     *   حالت random سقوط می‌کند.
     * - random (پیش‌فرض): همیشه به‌صورت «حروف‌اول‌سرور_حجم_شماره‌ترتیبی»
     *   ساخته می‌شود — برخلاف حالت custom، اینجا شماره‌ی ترتیبی همیشه
     *   حاضر است، نه فقط در صورت تکرار (طبق متن دقیق درخواست).
     *
     * $isTest: طبق درخواست صریح، نام اکانت تست همیشه بر اساس آیدی/نام
     * تلگرام کاربر است (که MiscHandler::testAccount در $customUsername
     * می‌فرستد) — مستقل از naming_mode سبد فروش، چون naming_mode فقط
     * برای خرید واقعی معنا دارد و کاربر اکانت تست هیچ نامی وارد نمی‌کند.
     */
    protected function generateUsername(Product $product, ServerPanel $panel, ?string $customUsername = null, bool $isTest = false): string
    {
        $driver = PanelDriverFactory::make($panel->panel_type);

        if (($isTest || $product->category->naming_mode === 'custom') && $customUsername) {
            return $this->uniqueUsername(
                Str::lower($customUsername),
                startBare: true,
                panel: $panel,
                driver: $driver,
            );
        }

        return $this->uniqueUsername(
            $this->randomUsernameBase($panel, $product),
            startBare: false,
            panel: $panel,
            driver: $driver,
        );
    }

    /**
     * طبق بازخورد صریح، به‌جای مخفف‌سازیِ چندکلمه‌ای (که خروجی‌اش برای
     * سرورهای تک‌کلمه‌ای یا کوتاه گنگ می‌شد)، همیشه دقیقاً ۴ حرفِ اول
     * نام سرور (بدون فاصله/کاراکتر خاص) گرفته می‌شود — هم ساده‌تر و
     * قابل‌پیش‌بینی‌تر است، هم تضمین می‌کند «_» همیشه دقیقاً بین این
     * پیشوند و حجم قرار بگیرد.
     */
    protected function randomUsernameBase(ServerPanel $panel, Product $product): string
    {
        $letters = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $panel->name));
        $prefix = Str::substr($letters, 0, 4) ?: 'srv';

        $volume = $product->traffic_gb ? (string) (int) round((float) $product->traffic_gb) : 'unl';

        return "{$prefix}_{$volume}";
    }

    /**
     * اگر $startBare باشد و $base هنوز آزاد باشد، خودِ $base بدون هیچ
     * پسوندی برگردانده می‌شود (رفتار موردنیاز حالت custom در اولین بار).
     * در غیر این صورت (یا وقتی $base از قبل اشغال بود)، از عدد ۱ شروع به
     * افزودن پسوند می‌کند تا به یک نام آزاد برسد (رفتار حالت random —
     * که همیشه پسوند دارد — و رفتار حالت custom در صورت تکراری بودن).
     */
    protected function uniqueUsername(
        string $base,
        bool $startBare,
        ServerPanel $panel,
        object $driver,
    ): string {
        $base = trim($base, '_') ?: 'user';

        /*
        * حالت custom:
        * اگر خود نام آزاد باشد، همان را استفاده می‌کنیم.
        *
        * توجه:
        * برای custom فعلاً همان رفتار قبلی حفظ شده و در صورت
        * تکراری بودن از _1، _2، ... استفاده می‌کنیم.
        */
        if ($startBare) {
            if (
                ! Account::query()
                    ->where('panel_username', $base)
                    ->exists()
                && ! $this->usernameExistsOnPanel($driver, $panel, $base)
            ) {
                return $base;
            }

            $n = 1;

            while (true) {
                $candidate = "{$base}_{$n}";

                if (
                    ! Account::query()
                        ->where('panel_username', $candidate)
                        ->exists()
                    && ! $this->usernameExistsOnPanel($driver, $panel, $candidate)
                ) {
                    return $candidate;
                }

                $n++;
            }
        }

        /*
        * حالت random:
        *
        * base:
        *   vip1_20
        *
        * ترتیب:
        *   vip1_20_1
        *   vip1_20_1a
        *   vip1_20_1b
        *   ...
        *   vip1_20_1z
        *   vip1_20_2
        *   vip1_20_2a
        *   ...
        */
        $sequence = 1;

        while (true) {
            $numberCandidate = "{$base}_{$sequence}";

            if (
                ! Account::query()
                    ->where('panel_username', $numberCandidate)
                    ->exists()
                && ! $this->usernameExistsOnPanel($driver, $panel, $numberCandidate)
            ) {
                return $numberCandidate;
            }

            foreach (range('a', 'z') as $letter) {
                $candidate = "{$base}_{$sequence}{$letter}";

                if (
                    ! Account::query()
                        ->where('panel_username', $candidate)
                        ->exists()
                    && ! $this->usernameExistsOnPanel($driver, $panel, $candidate)
                ) {
                    return $candidate;
                }
            }

            $sequence++;
        }
    }

    protected function usernameExistsOnPanel(
        object $driver,
        ServerPanel $panel,
        string $username,
    ): bool {
        if ($driver instanceof SupportsUsernameAvailability) {
            return $driver->usernameExists($panel, $username);
        }

        return false;
    }
}
