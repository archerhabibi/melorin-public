<?php

namespace App\Services\Core;

use App\DataTransferObjects\PanelAccountRequest;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\ResellerScopeViolationException;
use App\Models\Account;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\SupportsUsernameAvailability;
use App\Services\Core\ServerSelection\ServerSelectionStrategy;
use Illuminate\Support\Facades\DB;
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
    ): Account {
        return DB::transaction(function () use ($user, $product, $manualPanel, $salesChannel, $reseller, $customUsername, $isTest, $testTrafficMb, $testDurationHours) {

            // طبق سند معماری Reseller Platform نسخه‌ی ۱.۱ (بخش ۷،
            // «Double-Debit Purchase») — نه یک پرداخت ساده، بلکه دو
            // کسر مستقل هم‌زمان: مشتری قیمتِ فروشِ نماینده را از کیف‌پول
            // خودش می‌پردازد، و نماینده هم‌زمان هزینه‌ی پایه/عمده‌فروشی
            // را از اعتبار خودش نزد پلتفرم می‌پردازد. سودِ نماینده هرگز
            // به‌صورت جداگانه پرداخت نمی‌شود — همان تفاوتِ این دو عدد
            // است که نماینده در دنیای واقعی (نقدی/کارت‌به‌کارت مستقیم
            // با مشتری‌اش) از قبل دریافت کرده. طبق بند ۵ سند نیازمندی:
            // محصول باید صراحتاً توسط همین نماینده فعال و قیمت‌گذاری
            // شده باشد (مدل opt-in)، وگرنه غیرقابل‌فروش است.
            $sellingPrice = null;

            if ($reseller && ! $isTest) {
                // «Customer belongs to reseller scope» — طبق بخش ۷ سند
                // Melorin-Reseller-Platform-Spec. تغییر id در callback/
                // request نباید بتواند این چک را دور بزند.
                if ($user->reseller_id !== $reseller->id) {
                    throw new ResellerScopeViolationException('این کاربر مشتری این نماینده نیست.');
                }

                $sellingPrice = $product->sellingPriceForReseller($reseller);

                if ($sellingPrice === null) {
                    throw new \RuntimeException('این محصول برای این نماینده قابل‌فروش نیست.');
                }

                // هر دو موجودی پیش از هر کسری بررسی می‌شوند (طبق سند:
                // «customer_balance >= sold_price، reseller_balance >=
                // base_price») تا در حالت معمولِ «موجودی ناکافی» اصلاً
                // نیازی به کسر-و-بازگشتِ یکی از دو طرف نباشد.
                if ($this->walletService->balance($user) < $sellingPrice) {
                    throw new InsufficientBalanceException('موجودی کیف پول مشتری کافی نیست.');
                }

                if ($this->walletService->balance($reseller) < $product->resellerBasePrice()) {
                    throw new InsufficientBalanceException('موجودی اعتبار نماینده کافی نیست.');
                }
            }

            $soldPrice = $isTest ? 0.0 : ($sellingPrice ?? $product->priceForReseller($reseller));

            // ۱. انتخاب سرور — دستی یا خودکار بسته به تنظیمات دسته‌بندی (بند ۶)
            $panel = $manualPanel ?? $this->serverSelection->select($product->category);

            if (! $panel) {
                throw new \RuntimeException('هیچ سرور فعالی برای این دسته‌بندی در دسترس نیست.');
            }

            // ۲. ثبت سفارش با وضعیت pending
            $order = Order::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'reseller_id' => $reseller?->id,
                'sales_channel' => $salesChannel,
                'base_price' => $reseller ? $product->resellerBasePrice() : $product->price,
                'sold_price' => $soldPrice,
                'status' => 'pending',
            ]);

            // ۳. کسر مبلغ.
            // فروش مستقیم ربات اصلی: فقط کیف‌پول کاربر، به‌اندازه‌ی
            // قیمت فروش، کسر می‌شود (بدون تغییر نسبت به قبل).
            // فروش نمایندگی: طبق بخش ۷ سند معماری Reseller Platform
            // (Double-Debit)، دو کسرِ مستقل: کیف‌پول مشتری به‌اندازه‌ی
            // sold_price، و اعتبار نماینده به‌اندازه‌ی base_price —
            // هر دو در همین Transaction دیتابیسی، تا هرگز حالتی مثل
            // «مشتری کسر شد ولی نماینده نه» یا برعکس باقی نماند.
            // برای اکانت تست، مبلغ همیشه صفر است پس اصلاً کیف‌پولی کسر نمی‌شود.
            if (! $isTest) {
                $this->walletService->purchase(
                    $user,
                    (float) $soldPrice,
                    $order,
                    "خرید محصول «{$product->name}» — سفارش #{$order->id}"
                );

                if ($reseller) {
                    $this->walletService->purchase(
                        $reseller,
                        $product->resellerBasePrice(),
                        $order,
                        "هزینه‌ی پایه‌ی فروش نمایندگی — سفارش #{$order->id}"
                    );
                }
            }

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
                // بازگشت وجه در صورت شکست ساخت اکانت، تا کسی متضرر نشود —
                // برای فروش نمایندگی هر دو طرف (مشتری و نماینده) که کسر
                // شده بودند، هر دو برمی‌گردند (طبق سند: «No orphan
                // debit»). برای اکانت تست چیزی کسر نشده بود، پس چیزی هم
                // برنمی‌گردد.
                if (! $isTest) {
                    $this->walletService->refund($user, (float) $soldPrice, $order, 'بازگشت به دلیل خطای ساخت اکانت');

                    if ($reseller) {
                        $this->walletService->refund($reseller, $product->resellerBasePrice(), $order, 'بازگشت هزینه‌ی پایه به دلیل خطای ساخت اکانت');
                    }
                }
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

            return $account;
        });
    }

    /** تمدید اکانت موجود (بند ۱۰) */
    public function renew(Account $account, int $additionalDays, ?int $additionalTrafficGb = null): Account
    {
        $panel = $account->serverPanel;
        $driver = PanelDriverFactory::make($panel->panel_type);

        $newExpiry = $account->expires_at->isPast()
            ? now()->addDays($additionalDays)
            : $account->expires_at->addDays($additionalDays);

        $newTraffic = $additionalTrafficGb
            ? (float) $account->traffic_gb + $additionalTrafficGb
            : $account->traffic_gb;

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

        $account->update([
            'expires_at' => $newExpiry,
            'traffic_gb' => $newTraffic,
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
