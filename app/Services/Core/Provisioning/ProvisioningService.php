<?php

namespace App\Services\Core\Provisioning;

use App\DataTransferObjects\PanelAccountRequest;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Order;
use App\Models\ServerPanel;
use App\Services\Core\Panels\PanelDriverFactory;
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

        // اگر اکانت از قبل ساخته شده (retry روی سفارشی که در واقع موفق
        // بوده)، همان را برمی‌گردانیم. بدون این چک، یک retry می‌توانست
        // اکانت دوم روی پنل بسازد و ظرفیت را دوبرابر مصرف کند.
        if ($existing = $order->account) {
            return $existing;
        }

        $product = $order->product;
        $panel = $manualPanel ?? $this->serverSelection->select($product->category);

        if (! $panel) {
            $this->recordFailure($order, 'هیچ سرور فعالی برای این دسته‌بندی در دسترس نیست.');

            throw new ProvisioningFailedException('هیچ سرور فعالی برای این دسته‌بندی در دسترس نیست.');
        }

        $order->update([
            'status' => Order::STATUS_PROVISIONING,
            'provision_attempts' => $order->provision_attempts + 1,
        ]);

        $username = $customUsername ?: $this->generateUsername($order, $panel);
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
            // سفارش را در وضعیت provisioning معلق بگذارد.
            $this->recordFailure($order, $e->getMessage(), $operation);

            throw new ProvisioningFailedException("خطا در ارتباط با پنل: {$e->getMessage()}", previous: $e);
        }

        if (! $result->success) {
            $this->recordFailure($order, (string) $result->errorMessage, $operation);

            throw new ProvisioningFailedException("ساخت اکانت روی پنل ناموفق بود: {$result->errorMessage}");
        }

        // از این نقطه اکانت واقعاً روی پنل وجود دارد. اگر ثبت در
        // دیتابیس شکست بخورد، صریحاً از روی پنل پاکش می‌کنیم — وگرنه
        // همان اکانت یتیمی می‌شود که این معماری برای جلوگیری از آن
        // طراحی شده.
        try {
            return DB::transaction(function () use ($order, $panel, $product, $username, $clientUuid, $result, $expiresAt, $trafficBytes) {
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
                ]);

                $panel->increment('active_accounts_count');

                return $account;
            });
        } catch (\Throwable $e) {
            $this->rollbackOnPanel($panel, $username);
            $this->recordFailure($order, "ثبت اکانت در دیتابیس شکست خورد: {$e->getMessage()}", $operation);

            throw new ProvisioningFailedException('ثبت اکانت ناموفق بود.', previous: $e);
        }
    }

    /**
     * بند ۲۵ — آیا این سفارش هنوز فرصت تلاش مجدد دارد؟
     *
     * بعد از سه تلاش، سفارش در provision_failed می‌ماند تا ادمین دستی
     * رسیدگی کند. عمداً بازگشت وجه خودکار انجام نمی‌شود: ممکن است اکانت
     * روی پنل واقعاً ساخته شده باشد و فقط پاسخ به ما نرسیده باشد، و
     * بازگشت خودکار در آن حالت یعنی هم سرویس داده‌ایم هم پول برگردانده‌ایم.
     * تصمیم با ادمین است.
     */
    public function canRetry(Order $order): bool
    {
        return $order->status === Order::STATUS_PROVISION_FAILED
            && $order->provision_attempts < self::MAX_ATTEMPTS;
    }

    protected function recordFailure(Order $order, string $reason, ?Operation $operation = null): void
    {
        $order->update([
            'status' => Order::STATUS_PROVISION_FAILED,
            'failure_reason' => mb_substr($reason, 0, 1000),
        ]);

        // زمان‌بندی تلاش بعدی با فاصله‌ی فزاینده، تا اگر پنل موقتاً
        // پایین است، سه تلاش پشت‌سرهم در چند ثانیه هدر نرود.
        $operation?->markFailed(
            $reason,
            $order->provision_attempts < self::MAX_ATTEMPTS
                ? now()->addMinutes(2 ** $order->provision_attempts)
                : null,
        );

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
    protected function rollbackOnPanel(ServerPanel $panel, string $username): void
    {
        try {
            PanelDriverFactory::make($panel->panel_type)->deleteAccount($panel, $username);
        } catch (\Throwable $e) {
            Log::critical('orphan_panel_account', [
                'panel_id' => $panel->id,
                'username' => $username,
                'cleanup_error' => $e->getMessage(),
            ]);
        }
    }

    protected function generateUsername(Order $order, ServerPanel $panel): string
    {
        $prefix = $order->product->category?->naming_mode === 'order'
            ? "melorin{$order->id}"
            : 'melorin';

        return $prefix.'_'.Str::lower(Str::random(8));
    }
}
