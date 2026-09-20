<?php

namespace App\Services\Core\Renewal;

use App\DataTransferObjects\PanelAccountRequest;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Order;
use App\Services\Core\OperationService;
use App\Services\Core\Panels\PanelDriverFactory;
use App\Services\Core\Provisioning\ProvisioningFailureHandler;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Purchase\PriceSnapshot;
use App\Services\Core\Purchase\PurchaseGuard;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فاز E — تمدید اکانت به‌عنوان یک سرویس مستقل (بند ۲۸ بلوپرینت).
 *
 * تا امروز منطق تمدید داخل هندلرهای ربات پخش بود؛ یعنی هر کانال
 * نسخه‌ی خودش را داشت و دقیقاً به همین دلیل، تا نسخه‌ی ۳.۰.۷ مسیر
 * تمدید هنوز قیمت خرده‌فروشی را از نماینده می‌گرفت درحالی‌که مسیر خرید
 * از قبل اصلاح شده بود.
 *
 * دو نکته‌ای که بلوپرینت صریحاً روی آن‌ها تأکید دارد:
 *
 * ۱) بند ۲۸: «Renewal موفق باید هر دو را Reset/Extend کند: Time و
 *    Traffic. یعنی فقط تاریخ انقضا تغییر نکند.» تمدیدی که حجم را صفر
 *    نکند، از دید کاربری اصلاً تمدید نیست — اکانتی که حجمش تمام شده
 *    با تاریخ جدید هم کار نمی‌کند.
 *
 * ۲) بند ۲۹: اگر مالی موفق شد ولی پنل شکست خورد، سیستم نباید در وضعیت
 *    مبهم بماند. مثل خرید، مالی و پنل در دو مرحله‌ی جدا انجام می‌شوند و
 *    نتیجه روی Operation ثبت می‌شود.
 */
class RenewalService
{
    public function __construct(
        protected WalletService $wallet,
        protected PurchaseGuard $guard,
        protected OperationService $operations,
        protected ProvisioningService $provisioning,
        protected ProvisioningFailureHandler $failures,
    ) {}

    public function renew(Account $account, ?string $idempotencyKey = null): Account
    {
        $key = $idempotencyKey ?: sprintf('renewal:%d:%s', $account->id, now()->format('YmdHis'));

        $result = $this->operations->runOnce(
            $key,
            Operation::TYPE_RENEWAL,
            fn (Operation $operation) => $this->execute($account, $operation),
            payload: ['account_id' => $account->id],
        );

        return $result instanceof Account ? $result : $account->fresh();
    }

    protected function execute(Account $account, Operation $operation): Account
    {
        $customer = $account->customerAccount;
        $product = $account->product;

        if (! $customer) {
            throw new PurchaseNotAllowedException('مالک این اکانت مشخص نیست؛ با پشتیبانی تماس بگیرید.');
        }

        if (! $product) {
            throw new PurchaseNotAllowedException('محصول این اکانت دیگر موجود نیست؛ تمدید ممکن نیست.');
        }

        $store = StoreContext::fromReseller($customer->reseller);

        // تمدید از نظر مالی یک خرید کامل است، پس همان ترتیب خرید:
        // اول Context و sellability، بعد قیمت (بند ۱۵).
        $this->guard->assertContextAllowed($customer, $store);
        $this->guard->assertProductAvailable($product, $store);

        $customersPrice = $store->isReseller()
            ? $product->customersPrice($store->reseller)
            : null;

        $price = PriceSnapshot::for($store, $product, $customersPrice);

        // همان دروازه‌های خرید — شامل سقف بدهی نماینده. تمدید از نظر
        // مالی یک خرید کامل است و هیچ دلیلی ندارد قوانین سست‌تری داشته
        // باشد. چک سرور هم لازم نیست چون اکانت روی سرور موجودش تمدید
        // می‌شود، ولی چون assertCanPurchase آن را هم می‌سنجد و سرورِ
        // خودِ اکانت به‌هرحال فعال است، مشکلی ایجاد نمی‌کند.
        $this->guard->assertCanPurchase($customer, $product, $store, $price);

        // ── مرحله‌ی ۱: مالی، اتمیک ─────────────────────────────────
        $order = DB::transaction(function () use ($customer, $product, $store, $price, $operation, $account) {
            $order = Order::create(array_merge($price->toOrderColumns(), [
                'user_id' => $customer->user_id,
                'customer_account_id' => $customer->id,
                'product_id' => $product->id,
                'reseller_id' => $store->resellerId(),
                'renews_account_id' => $account->id,
                'sales_channel' => $store->isReseller() ? 'reseller_bot' : 'main_bot',
                'status' => Order::STATUS_PENDING,
            ]));

            if (! $price->isFree()) {
                // Debit اول: main_price یا customers_price، بسته به Context
                $this->wallet->debit(
                    $customer,
                    $price->customerDebit(),
                    'purchase',
                    $order,
                    "تمدید اکانت {$account->panel_username} — سفارش #{$order->id}",
                    $operation,
                );

                // Debit دوم: همیشه reseller_price (Rule 4 و Rule 6)
                if ($store->isReseller()) {
                    $this->wallet->debit(
                        $store->reseller,
                        $price->resellerDebit(),
                        'purchase',
                        $order,
                        "هزینه‌ی تأمین تمدید از Main (reseller_price) — سفارش #{$order->id}",
                        $operation,
                    );
                }
            }

            $order->update(['status' => Order::STATUS_PAID]);

            return $order;
        });

        // ── مرحله‌ی ۲: تمدید روی پنل، خارج از تراکنش ───────────────
        return $this->applyOnPanel($account, $product, $order, $operation);
    }

    /**
     * فاز ۱۱ — تلاش مجدد یک تمدیدِ شکست‌خورده روی پنل، بدون هیچ کسر جدید
     * (بند ۳۹). تاریخ جدید از روی رکورد محلی محاسبه می‌شود که تا موفقیت
     * تغییر نکرده؛ پس اگر تلاش قبلی روی پنل اثر کرده ولی پاسخش نرسیده
     * بود، تمدید دوبار اعمال نمی‌شود.
     */
    public function retry(Order $order, bool $force = false): Account
    {
        if (! $order->isRenewal()) {
            throw new PurchaseNotAllowedException("سفارش #{$order->id} یک سفارش تمدید نیست.");
        }

        $account = Account::query()->with(['product', 'serverPanel'])->find($order->renews_account_id);

        if (! $account) {
            throw new PurchaseNotAllowedException(
                "اکانتِ مربوط به تمدید سفارش #{$order->id} دیگر وجود ندارد؛ فقط بازگشت وجه ممکن است."
            );
        }

        $product = $order->product ?? $account->product;

        if (! $product) {
            throw new PurchaseNotAllowedException("محصول سفارش #{$order->id} دیگر موجود نیست؛ فقط بازگشت وجه ممکن است.");
        }

        if (! $this->provisioning->claimForRetry($order, $force)) {
            throw new PurchaseNotAllowedException(
                "سفارش #{$order->id} قابل تلاش مجدد نیست (وضعیت: {$order->status}، تلاش‌ها: {$order->provision_attempts})."
            );
        }

        return $this->applyOnPanel($account, $product, $order, null);
    }

    /**
     * تمدید واقعی روی پنل + به‌روزرسانی رکورد محلی.
     *
     * مبنای تاریخ انقضای جدید عمداً max(اکنون، انقضای فعلی) است: اگر
     * کاربر زودتر از موعد تمدید کند، روزهای باقی‌مانده‌اش نباید بسوزد؛
     * و اگر اکانت از قبل منقضی شده، نباید تاریخ جدید در گذشته بیفتد.
     */
    protected function applyOnPanel(Account $account, $product, Order $order, ?Operation $operation): Account
    {
        $order->update([
            'status' => Order::STATUS_PROVISIONING,
            'provision_attempts' => (int) $order->provision_attempts + 1,
        ]);

        try {
            $panel = $account->serverPanel;
            $base = $account->expires_at && $account->expires_at->isFuture()
                ? $account->expires_at->copy()
                : now();

            $newExpiry = $base->addDays((int) $product->duration_days);
            $trafficBytes = $product->traffic_gb ? (int) ($product->traffic_gb * 1024 ** 3) : 0;

            $driver = PanelDriverFactory::make($panel->panel_type);

            $result = $driver->updateAccount($panel, $account->panel_username, new PanelAccountRequest(
                username: $account->panel_username,
                trafficBytes: $trafficBytes,
                expireTimestamp: $newExpiry->timestamp,
                extra: array_merge($panel->extra_settings ?? [], [
                    'uuid' => $account->panel_client_uuid,
                    'sub_id' => $account->subscription_id,
                ]),
            ));

            if (! $result->success) {
                throw new RenewalFailedException("تمدید روی پنل ناموفق بود: {$result->errorMessage}");
            }

            // بند ۲۸: تمدید یعنی هم زمان و هم ترافیک. اگر پنل resetUsage
            // ندارد، تمدید شکست‌خورده اعلام نمی‌شود ولی لاگ می‌شود.
            try {
                $driver->resetUsage($panel, $account->panel_username);
            } catch (\Throwable $e) {
                Log::warning('renewal_traffic_reset_failed', [
                    'account_id' => $account->id,
                    'panel_id' => $panel->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            // مالی موفق، پنل ناموفق — همان حالتی که بند ۲۹ می‌گوید باید
            // صریح بماند. از اینجا سیاست شکست (فاز ۱۱) تصمیم می‌گیرد.
            $order->update([
                'status' => Order::STATUS_PROVISION_FAILED,
                'failure_reason' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            $operation?->markFailed($e->getMessage());

            Log::error('renewal_failed_after_payment', [
                'account_id' => $account->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $failure = $e instanceof RenewalFailedException
                ? $e
                : new RenewalFailedException("خطا در تمدید روی پنل: {$e->getMessage()}", previous: $e);

            throw $failure->withOutcome($this->failures->handle($order));
        }

        // ریست کامل در رکورد محلی
        $account->update([
            'expires_at' => $newExpiry,
            'traffic_gb' => $product->traffic_gb,
            'traffic_used_gb' => 0,
            'status' => 'active',
        ]);

        $order->update([
            'status' => Order::STATUS_ACCOUNT_CREATED,
            'failure_reason' => null,
            'next_provision_retry_at' => null,
        ]);

        return $account->fresh();
    }
}
