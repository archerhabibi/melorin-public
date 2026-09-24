<?php

namespace App\Services\Core\Provisioning;

use App\DataTransferObjects\PanelAccountRequest;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Models\ServerPanel;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Panels\SupportsUsernameAvailability;
use App\Services\Core\ServerSelection\ServerSelectionStrategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * فاز D — ساخت اکانت روی پنل VPN (بند ۲۲ تا ۲۵ بلوپرینت).
 *
 * مهم‌ترین تفاوت معماری با AccountService فعلی، مرز تراکنش است (بند ۴۷).
 * امروز کل خرید — شامل فراخوانی API خارجی پنل — داخل یک
 * DB::transaction انجام می‌شود. مشکل این است که یک تراکنش دیتابیس
 * نمی‌تواند اکانتی را که روی پنل ساخته شده rollback کند. اگر بعد از
 * ساخت موفق روی پنل، درج در دیتابیس شکست بخورد، rollback فقط ردیف‌ها
 * را برمی‌گرداند و یک اکانت یتیم روی پنل باقی می‌ماند که ظرفیت مصرف
 * می‌کند ولی هیچ رکوردی در ملورین ندارد.
 *
 * الگوی جدید:
 *
 *     DB Transaction  →  Commit مالی  →  [خارج از تراکنش] Provisioning
 *
 * یعنی وقتی این سرویس صدا زده می‌شود، پول از قبل قطعی کسر شده و سفارش
 * ثبت است. اگر ساخت اکانت شکست بخورد، وضعیت مالی مبهم نمی‌ماند: سفارش
 * به provision_failed می‌رود که صریحاً یعنی «پول گرفته شده، سرویس
 * تحویل نشده» و قابل retry است بدون کسر دوباره.
 */
class ProvisioningService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        protected ServerSelectionStrategy $serverSelection,
    ) {}

    /**
     * ساخت اکانت برای یک سفارشِ از قبل پرداخت‌شده.
     *
     * @throws ProvisioningFailedException
     */
    public function provision(
        Order $order,
        ?ServerPanel $manualPanel = null,
        ?string $customUsername = null,
        ?int $trafficBytesOverride = null,
        ?\DateTimeInterface $expiresAtOverride = null,
        ?Operation $operation = null,
    ): Account {
        if (! $order->isFinanciallySettled()) {
            throw new ProvisioningFailedException(
                "سفارش #{$order->id} هنوز تسویه‌ی مالی نشده؛ ساخت اکانت مجاز نیست."
            );
        }

        // سفارش تمدید هرگز اکانت جدید نمی‌سازد؛ مسیرش RenewalService::retry
        // است (بدون این چک، retry روی سفارش تمدید یک اکانت تازه می‌ساخت).
        if ($order->isRenewal()) {
            throw new ProvisioningFailedException(
                "سفارش #{$order->id} یک سفارش تمدید است؛ ساخت اکانت جدید برای آن مجاز نیست."
            );
        }

        // اگر اکانت از قبل ساخته شده (retry روی سفارشی که در واقع موفق
        // بوده)، همان را برمی‌گردانیم. بدون این چک، یک retry می‌توانست
        // اکانت دوم روی پنل بسازد و ظرفیت را دوبرابر مصرف کند. وضعیت
        // سفارش هم اصلاح می‌شود تا در provisioning/provision_failed نماند.
        if ($existing = $order->account) {
            if ($order->status !== Order::STATUS_ACCOUNT_CREATED) {
                $order->update([
                    'status' => Order::STATUS_ACCOUNT_CREATED,
                    'failure_reason' => null,
                    'next_provision_retry_at' => null,
                ]);
            }

            return $existing;
        }

        $product = $order->product;

        // فاز A2 سند v2.1 (بند ۶۵ — Capacity): انتخاب و رزروِ ظرفیت یک
        // قدمِ اتمیک است، نه یک select-و-بعد-increment جدا؛ پایین‌تر
        // توضیح داده شده که چرا. پنلِ دستیِ ادمین/کانال هم از همین قاعده
        // مستثنا نیست — انتخابِ دستی، ظرفیت واقعیِ پنل را زیاد نمی‌کند.
        $panel = $manualPanel
            ? ($manualPanel->reserveCapacitySlot() ? $manualPanel : null)
            : $this->serverSelection->selectAndReserve($product->category);

        if (! $panel) {
            // این هم یک تلاش حساب می‌شود؛ وگرنه شمارنده صفر می‌ماند و
            // retry (یا retry_then_refund) هرگز به سقف نمی‌رسید.
            $order->update(['provision_attempts' => (int) $order->provision_attempts + 1]);
            $attempt = $this->beginAttempt($order, $operation);

            $reason = $manualPanel
                ? 'پنل انتخاب‌شده به ظرفیت رسیده است.'
                : 'هیچ سرور فعال و دارای ظرفیت آزادی برای این دسته‌بندی در دسترس نیست.';

            $this->recordFailure($order, $reason, $operation, $attempt);

            throw new ProvisioningFailedException($reason);
        }

        $order->update([
            'status' => Order::STATUS_PROVISIONING,
            'provision_attempts' => $order->provision_attempts + 1,
        ]);

        $attempt = $this->beginAttempt($order, $operation);

        $username = $this->generateUsername(
            order: $order,
            panel: $panel,
            customUsername: $customUsername,
        );
        $clientUuid = (string) Str::uuid();
        $subId = Str::random(16);

        $expiresAt = $expiresAtOverride
            ? \Illuminate\Support\Carbon::parse($expiresAtOverride)
            : now()->addDays((int) $product->duration_days);

        $trafficBytes = $trafficBytesOverride
            ?? ($product->traffic_gb ? (int) ($product->traffic_gb * 1024 ** 3) : 0);

        $request = new PanelAccountRequest(
            username: $username,
            trafficBytes: $trafficBytes,
            expireTimestamp: $expiresAt->timestamp,
            note: "melorin-order-{$order->id}",
            extra: array_merge($panel->extra_settings ?? [], [
                'uuid' => $clientUuid,
                'tg_id' => $order->customerAccount?->user?->telegram_id ?? $order->user?->telegram_id,
                'sub_id' => $subId,
            ]),
        );

        $driver = PanelDriverFactory::make($panel->panel_type);

        try {
            $result = $driver->createAccount($panel, $request);
        } catch (\Throwable $e) {
            // استثنای غیرمنتظره (قطعی شبکه، خطای درایور) هم دقیقاً مثل
            // پاسخ ناموفق پنل رفتار می‌کند — نباید به بیرون نشت کند و
            // سفارش را در وضعیت provisioning معلق بگذارد. اکانتی روی پنل
            // واقعاً ساخته نشده، پس رزرو ظرفیت آزاد می‌شود.
            $panel->releaseCapacitySlot();
            $this->recordFailure($order, $e->getMessage(), $operation, $attempt);

            throw new ProvisioningFailedException("خطا در ارتباط با پنل: {$e->getMessage()}", previous: $e);
        }

        if (! $result->success) {
            $panel->releaseCapacitySlot();
            $this->recordFailure($order, (string) $result->errorMessage, $operation, $attempt);

            throw new ProvisioningFailedException("ساخت اکانت روی پنل ناموفق بود: {$result->errorMessage}");
        }

        // از این نقطه اکانت واقعاً روی پنل وجود دارد. اگر ثبت در
        // دیتابیس شکست بخورد، صریحاً از روی پنل پاکش می‌کنیم — وگرنه
        // همان اکانت یتیمی می‌شود که این معماری برای جلوگیری از آن
        // طراحی شده.
        try {
            return DB::transaction(function () use ($order, $panel, $product, $username, $clientUuid, $result, $expiresAt, $trafficBytes, $attempt) {
                $account = Account::create([
                    'user_id' => $order->user_id,
                    'customer_account_id' => $order->customer_account_id,
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
                    'traffic_gb' => $trafficBytes ? round($trafficBytes / 1024 ** 3, 4) : null,
                    'status' => 'active',
                    'is_test' => false,
                ]);

                $order->update([
                    'status' => Order::STATUS_ACCOUNT_CREATED,
                    'failure_reason' => null,
                    'next_provision_retry_at' => null,
                ]);

                // ظرفیت از قبل رزرو شده بود (بالای همین متد)؛ اینجا فقط
                // تثبیت می‌شود، دیگر افزایشی در کار نیست.

                $attempt->update(['status' => ProvisioningAttempt::STATUS_SUCCEEDED]);

                return $account;
            });
        } catch (\Throwable $e) {
            // اکانت واقعاً روی پنل ساخته شده بود. اگر پاک‌کردنش از پنل
            // موفق شود، آن واحد ظرفیت واقعاً آزاد شده و رزرو را پس
            // می‌دهیم؛ اگر موفق نشود، اکانتِ یتیم همچنان آن ظرفیت را
            // مصرف می‌کند — آزادکردنِ رزرو در آن حالت یعنی فروختن دوباره‌ی
            // چیزی که فیزیکاً هنوز اشغال است.
            if ($this->rollbackOnPanel($panel, $username)) {
                $panel->releaseCapacitySlot();
            }

            $this->recordFailure($order, "ثبت اکانت در دیتابیس شکست خورد: {$e->getMessage()}", $operation, $attempt);

            throw new ProvisioningFailedException('ثبت اکانت ناموفق بود.', previous: $e);
        }
    }

    /**
     * بند ۶۹ (فاز A3) — ثبتِ شروعِ یک تلاشِ Provisioning، مستقل از
     * شمارنده‌ی orders.provision_attempts. attempt_number از همان
     * شمارنده خوانده می‌شود چون در این نقطه از متد، همیشه یک قدم قبل‌تر
     * (بالای همین متد) افزایش یافته است — یعنی این تلاش، تلاش شماره‌ی
     * آن مقدار است.
     */
    protected function beginAttempt(Order $order, ?Operation $operation): ProvisioningAttempt
    {
        return ProvisioningAttempt::create([
            'order_id' => $order->id,
            'operation_id' => $operation?->id,
            'attempt_number' => (int) $order->provision_attempts,
            'status' => ProvisioningAttempt::STATUS_STARTED,
        ]);
    }

    /**
     * رزرو اتمیک سفارش برای یک تلاش مجدد (provision_failed → provisioning).
     *
     * یک UPDATE شرطی است، نه «بخوان و بعد بنویس»: اگر Command زمان‌بندی‌شده
     * و کلیک ادمین هم‌زمان برسند، فقط یکی ۱ ردیف را تغییر می‌دهد و دیگری
     * false می‌گیرد — پس دو تلاش موازی (دو اکانت روی پنل) ممکن نیست.
     *
     * $force=true سقف MAX_ATTEMPTS را نادیده می‌گیرد (فقط تصمیم دستی ادمین).
     */
    public function claimForRetry(Order $order, bool $force = false): bool
    {
        $query = Order::query()
            ->whereKey($order->getKey())
            ->where('status', Order::STATUS_PROVISION_FAILED);

        if (! $force) {
           $query->where('provision_attempts', '<=', self::MAX_ATTEMPTS);
        }

        $claimed = $query->update([
            'status' => Order::STATUS_PROVISIONING,
            'next_provision_retry_at' => null,
        ]) === 1;

        if ($claimed) {
            $order->refresh();
        }

        return $claimed;
    }

    /**
     * بند ۲۵ — آیا این سفارش هنوز فرصت تلاش مجدد دارد؟
     *
     * بعد از سه تلاش، سفارش در provision_failed می‌ماند تا ادمین دستی
     * رسیدگی کند (مگر سیاست retry_then_refund که بعد از تلاش سوم خودکار
     * بازگشت می‌دهد — ProvisioningFailureHandler). در سیاست retry بازگشت
     * خودکار انجام نمی‌شود: ممکن است اکانت
     * روی پنل واقعاً ساخته شده باشد و فقط پاسخ به ما نرسیده باشد، و
     * بازگشت خودکار در آن حالت یعنی هم سرویس داده‌ایم هم پول برگردانده‌ایم.
     * تصمیم با ادمین است.
     */
    public function canRetry(Order $order): bool
    {
        return $order->status === Order::STATUS_PROVISION_FAILED
            && $order->provision_attempts < self::MAX_ATTEMPTS;
    }

    public function recordFailure(
        Order $order,
        string $reason,
        ?Operation $operation = null,
        ?ProvisioningAttempt $attempt = null,
    ): void {
        $order->update([
            'status' => Order::STATUS_PROVISION_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);

        // اگر تماس‌گیرنده (مثلاً کاتچِ استثنای غیرمنتظره‌ی
        // PurchaseService::retryProvisioning) رکورد تلاش را در دست
        // ندارد، آخرین تلاشِ «شروع‌شده»ی همین سفارش را پیدا می‌کنیم تا
        // بدون Attempt یتیم در وضعیت started نماند.
        $attempt ??= $order->provisioningAttempts()
            ->where('status', ProvisioningAttempt::STATUS_STARTED)
            ->latest('id')
            ->first();

        $attempt?->update(['status' => ProvisioningAttempt::STATUS_FAILED]);

        $operation?->markFailed($reason);

        Log::error('provisioning_failed', [
            'order_id' => $order->id,
            'attempt' => $order->provision_attempts,
            'reason' => $reason,
            'needs_admin' => $order->provision_attempts >= self::MAX_ATTEMPTS,
        ]);
    }

    /**
     * تلاش برای پاک‌کردن اکانتی که روی پنل ساخته شد ولی نتوانستیم
     * ثبتش کنیم. اگر خودِ این هم شکست بخورد فقط لاگ می‌شود — چون در آن
     * نقطه بهترین کاری که می‌شود کرد این است که ادمین بداند یک اکانت
     * یتیم با این نام روی این پنل هست.
     */
    protected function rollbackOnPanel(ServerPanel $panel, string $username): bool
    {
        try {
            PanelDriverFactory::make($panel->panel_type)->deleteAccount($panel, $username);

            return true;
        } catch (\Throwable $e) {
            Log::critical('orphan_panel_account', [
                'panel_id' => $panel->id,
                'username' => $username,
                'cleanup_error' => $e->getMessage(),
            ]);

            return false;
        }
    }

        /**
     * تولید نام کاربری اکانت بر اساس naming_mode محصول.
     *
     * random:
     *   germ_30_1
     *   germ_30_1a
     *   germ_30_1b
     *   ...
     *   germ_30_1z
     *   germ_30_2
     *
     * custom:
     *   ali
     *   ali_1
     *   ali_2
     *
     * order:
     *   Vip2_40A152
     *
     * در حالت order:
     *   4 کاراکتر اول نام سرور + ترافیک + حرف اول نماینده + order_id
     */
    protected function generateUsername(
        Order $order,
        ServerPanel $panel,
        ?string $customUsername = null,
    ): string {
        $mode = $order->product->category?->naming_mode ?? 'random';

        $driver = PanelDriverFactory::make($panel->panel_type);

        return match ($mode) {
            'custom' => $this->uniqueUsername(
                base: Str::lower(trim((string) $customUsername)),
                panel: $panel,
                driver: $driver,
                startBare: true,
            ),

            'order' => $this->uniqueUsername(
                base: $this->orderUsernameBase($order, $panel),
                panel: $panel,
                driver: $driver,
                startBare: true,
            ),

            default => $this->uniqueUsername(
                base: $this->randomUsernameBase($order, $panel),
                panel: $panel,
                driver: $driver,
                startBare: false,
            ),
        };
    }

    /**
     * random:
     * 4 کاراکتر اول alphanumeric نام سرور + traffic.
     *
     * مثال:
     * germ + 30 => germ_30
     */
    protected function randomUsernameBase(
        Order $order,
        ServerPanel $panel,
    ): string {
        $serverName = Str::lower(
            preg_replace('/[^A-Za-z0-9]/', '', $panel->name) ?? ''
        );

        $prefix = Str::substr($serverName, 0, 4) ?: 'srv';

        $traffic = $order->product->traffic_gb
            ? (string) (int) round((float) $order->product->traffic_gb)
            : 'unl';

        return "{$prefix}_{$traffic}";
    }

    /**
     * order:
     * [4 chars server]_[traffic][reseller initial][order_id]
     *
     * مثال:
     * Vip2 + 40GB + Ali + 152
     * => Vip2_40A152
     */
    protected function orderUsernameBase(
        Order $order,
        ServerPanel $panel,
    ): string {
        $serverName = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $panel->name
        ) ?? '';

        $serverPrefix = Str::substr($serverName, 0, 4) ?: 'srv';

        $traffic = $order->product->traffic_gb
            ? (string) (int) round((float) $order->product->traffic_gb)
            : 'unl';

        $reseller = $order->reseller;

        if (! $reseller) {
            throw new \RuntimeException(
                'حالت نام‌گذاری order فقط برای سفارش نماینده قابل استفاده است.'
            );
        }

        $resellerName = trim((string) ($reseller->user?->full_name ?? ''));

        $initial = Str::upper(
            Str::substr($resellerName, 0, 1)
        );

        if (! preg_match('/^[A-Z]$/', $initial)) {
            throw new \RuntimeException(
                'برای نام‌گذاری order، نام نماینده باید با یک حرف انگلیسی شروع شود.'
            );
        }

        return "{$serverPrefix}_{$traffic}{$initial}{$order->id}";
    }

    /**
     * بررسی یکتا بودن username در دیتابیس و در صورت پشتیبانی، روی پنل.
     */
    protected function uniqueUsername(
        string $base,
        ServerPanel $panel,
        object $driver,
        bool $startBare,
    ): string {
        $base = trim($base);

        if ($base === '') {
            throw new \RuntimeException(
                'نام کاربری تولیدشده خالی است.'
            );
        }

        if ($startBare && ! $this->usernameExists($base, $panel, $driver)) {
            return $base;
        }

        if (! $startBare) {
            $candidate = "{$base}_1";

            if (! $this->usernameExists($candidate, $panel, $driver)) {
                return $candidate;
            }

            for ($letter = 'a'; $letter <= 'z'; $letter++) {
                $candidate = "{$base}_1{$letter}";

                if (! $this->usernameExists($candidate, $panel, $driver)) {
                    return $candidate;
                }
            }

            $number = 2;

            while (true) {
                $candidate = "{$base}_{$number}";

                if (! $this->usernameExists($candidate, $panel, $driver)) {
                    return $candidate;
                }

                $number++;
            }
        }

        $number = 1;

        while (true) {
            $candidate = "{$base}_{$number}";

            if (! $this->usernameExists($candidate, $panel, $driver)) {
                return $candidate;
            }

            $number++;
        }
    }

    /**
     * بررسی وجود username در دیتابیس و پنل.
     */
    protected function usernameExists(
        string $username,
        ServerPanel $panel,
        object $driver,
    ): bool {
        $existsInDatabase = Account::query()
        ->where('panel_username', $username)
        ->exists();

        if ($existsInDatabase) {
            return true;
        }

        if ($driver instanceof SupportsUsernameAvailability) {
            return $driver->usernameExists($panel, $username);
        }

        return false;
    }
}
