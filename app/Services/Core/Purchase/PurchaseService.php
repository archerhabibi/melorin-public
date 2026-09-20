<?php

namespace App\Services\Core\Purchase;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Services\Core\Affiliate\CommissionService;
use App\Services\Core\Affiliate\ReferralService;
use App\Services\Core\OperationService;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Provisioning\ProvisioningFailureHandler;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * فاز B و C — تنها نقطه‌ی ورود خرید در کل سیستم (بند ۱۴ بلوپرینت:
 * «هیچ Bot یا Website نباید این زنجیره را خودش duplicate کند»).
 *
 * دو تصمیم معماری که این کلاس را از AccountService فعلی متمایز می‌کند:
 *
 * ۱) مرز تراکنش (بند ۴۷ و ۲۱). مالی و Provisioning از هم جدا شده‌اند:
 *
 *        DB Transaction { سفارش + کسرها + Operation }   ← اتمیک
 *                          ↓ commit
 *        Provisioning (API خارجی)                       ← خارج از تراکنش
 *
 *    دلیل: تراکنش دیتابیس نمی‌تواند یک فراخوانی API خارجی را rollback
 *    کند. نگه‌داشتن هر دو در یک تراکنش یعنی یا اکانت یتیم روی پنل
 *    می‌ماند، یا تراکنش چند ثانیه (طول پاسخ پنل) باز می‌ماند و ردیف‌ها
 *    را قفل نگه می‌دارد.
 *
 * ۲) Double-Debit اتمیک (بند ۱۹ و ۲۱). در فروش نمایندگی دو کسر مستقل
 *    داریم — مشتری و نماینده — که باید یک عملیات منطقی واحد باشند.
 *    هر دو داخل همان یک تراکنش‌اند، پس حالت «مشتری کسر شد ولی نماینده
 *    نه» ممکن نیست.
 */
class PurchaseService
{
    public function __construct(
        protected WalletService $wallet,
        protected PurchaseGuard $guard,
        protected ProvisioningService $provisioning,
        protected OperationService $operations,
        protected CommissionService $commissions,
        protected ReferralService $referrals,
        protected ProvisioningFailureHandler $failures,
    ) {}

    /**
     * خرید کامل: از بررسی دروازه‌ها تا تحویل اکانت.
     *
     * $idempotencyKey را همیشه از لایه‌ی کانال بدهید (مثلاً شناسه‌ی
     * callback تلگرام یا توکن فرم سایت). بدون آن، دوبار کلیک کاربر
     * یعنی دو خرید واقعی.
     */
    public function purchase(
        CustomerAccount $customer,
        Product $product,
        StoreContext $store,
        string $salesChannel = 'main_bot',
        ?ServerPanel $manualPanel = null,
        ?string $customUsername = null,
        ?string $idempotencyKey = null,
    ): Account {
        $key = $idempotencyKey ?: sprintf(
            'purchase:%d:%d:%s', $customer->id, $product->id, now()->format('YmdHis')
        );

        $result = $this->operations->runOnce(
            $key,
            Operation::TYPE_PURCHASE,
            fn (Operation $operation) => $this->execute(
                $customer, $product, $store, $salesChannel, $manualPanel, $customUsername, $operation
            ),
            payload: array_merge($store->toArray(), [
                'customer_account_id' => $customer->id,
                'product_id' => $product->id,
            ]),
        );

        // اگر عملیات قبلاً اجرا شده بود، runOnce به‌جای مدل، همان
        // result_payload ذخیره‌شده را برمی‌گرداند. اینجا دوباره به مدل
        // تبدیلش می‌کنیم تا فراخواننده همیشه یک Account بگیرد و مجبور
        // نباشد خودش دو حالت را مدیریت کند.
        if ($result instanceof Account) {
            return $result;
        }

        if (is_array($result) && isset($result['id'])) {
            return Account::findOrFail($result['id']);
        }

        throw new PurchaseNotAllowedException('نتیجه‌ی این خرید قابل بازیابی نیست؛ با پشتیبانی تماس بگیرید.');
    }

    protected function execute(
        CustomerAccount $customer,
        Product $product,
        StoreContext $store,
        string $salesChannel,
        ?ServerPanel $manualPanel,
        ?string $customUsername,
        Operation $operation,
    ): Account {
        // ترتیب این سه گام عمدی است و نباید جابه‌جا شود:
        //
        //   ۱. Context  — آیا این فروشگاه فعال است و این مشتری عضو
        //                 همین فروشگاه است؟ (بند ۱۵ و ۱۶)
        //   ۲. Sellability — آیا این محصول اصلاً از این فروشگاه
        //                 قابل‌فروش است؟ این یک مرز امنیتی است و باید
        //                 پیش از هر محاسبه‌ی قیمتی اجرا شود، وگرنه
        //                 محصولِ غیرفعال/قیمت‌گذاری‌نشده به‌جای
        //                 ProductNotSellableException یک
        //                 InvalidArgumentException از دلِ PriceSnapshot
        //                 بیرون می‌دهد — یعنی همان خطا، با پیامی که نه
        //                 برای کاربر معنا دارد نه برای مانیتورینگ.
        //   ۳. قیمت و موجودی — تازه حالا.
        $this->guard->assertContextAllowed($customer, $store);
        $this->guard->assertProductAvailable($product, $store);

        // customers_price فقط در Context نمایندگی معنا دارد (بند ۶ و
        // Rule 7). در فروشگاه اصلی عمداً null می‌ماند تا PriceSnapshot
        // خودش main_price را بردارد.
        $customersPrice = $store->isReseller()
            ? $product->customersPrice($store->reseller)
            : null;

        $price = PriceSnapshot::for($store, $product, $customersPrice);

        // بقیه‌ی دروازه‌ها پیش از هر تغییر مالی (بند ۱۵ و ۲۰)
        $this->guard->assertCanPurchase($customer, $product, $store, $price);

        // ── مرحله‌ی ۱: تسویه‌ی مالی، اتمیک ──────────────────────────
        $order = DB::transaction(function () use ($customer, $product, $store, $salesChannel, $price, $operation) {
            $order = Order::create(array_merge($price->toOrderColumns(), [
                'user_id' => $customer->user_id,
                'customer_account_id' => $customer->id,
                'product_id' => $product->id,
                'reseller_id' => $store->resellerId(),
                'sales_channel' => $salesChannel,
                'status' => Order::STATUS_PENDING,
            ]));

            if (! $price->isFree()) {
                // Debit اول (بند ۱۳): از کیف‌پول مشتری در Context خودش.
                // Main → main_price · Reseller → customers_price
                $this->wallet->debit(
                    $customer,
                    $price->customerDebit(),
                    'purchase',
                    $order,
                    "خرید «{$product->name}» — سفارش #{$order->id}",
                    $operation,
                );

                // Debit دوم (بند ۱۳): از کیف‌پول نماینده در Main، دقیقاً
                // به‌اندازه‌ی reseller_price — نه customers_price
                // (Rule 6). هر دو در همین یک تراکنش، پس حالت «یکی کسر
                // شد و دیگری نه» ممکن نیست (بند ۲۲).
                if ($store->isReseller()) {
                    $this->wallet->debit(
                        $store->reseller,
                        $price->resellerDebit(),
                        'purchase',
                        $order,
                        "هزینه‌ی تأمین محصول از Main (reseller_price) — سفارش #{$order->id}",
                        $operation,
                    );
                }
            }

            $order->update(['status' => Order::STATUS_PAID]);

            return $order;
        });

        // ── مرحله‌ی ۲: Provisioning، خارج از تراکنش ────────────────
        //
        // از این نقطه پول قطعی کسر شده. اگر ساخت اکانت شکست بخورد،
        // سفارش در provision_failed می‌ماند — که صریحاً یعنی «پول گرفته
        // شده، سرویس تحویل نشده» و با retry بدون کسر دوباره قابل جبران
        // است. این عمداً با شکست مالی (failed) اشتباه گرفته نمی‌شود.
        try {
            $account = $this->provisioning->provision(
                order: $order,
                manualPanel: $manualPanel,
                customUsername: $customUsername,
                operation: $operation,
            );
        } catch (ProvisioningFailedException $e) {
            // فاز ۱۱: سیاست شکست (retry / refund / retry_then_refund) در
            // پنل ادمین انتخاب می‌شود و ProvisioningFailureHandler آن را
            // برای خرید و تمدید یکسان اعمال می‌کند. نتیجه روی استثنا
            // می‌نشیند تا کانال به مشتری پیام درست بدهد.
            throw $e->withOutcome($this->failures->handle($order));
        }

        // ── مرحله‌ی ۳: پاداش و کمیسیون (فاز F) ─────────────────────
        //
        // عمداً بعد از تحویل موفق سرویس و خارج از هر تراکنشی: این‌ها
        // مزیت جانبی‌اند و شکستشان نباید خریدی را که پولش گرفته شده و
        // اکانتش ساخته شده، شکست‌خورده جلوه دهد. هر دو سرویس خودشان
        // استثنا را می‌بلعند و فقط لاگ می‌کنند.
        //
        // ترتیب اهمیت دارد: اول پاداش اولین خرید (که خودش بررسی می‌کند
        // واقعاً اولین است یا نه)، بعد کمیسیون درصدی. جابه‌جا کردنشان
        // باعث می‌شد سفارش جاری در شمارش «اولین خرید» حساب شود.
        $this->awardBenefits($order, $operation);

        return $account;
    }

    protected function awardBenefits(Order $order, ?Operation $operation = null): void
    {
        $this->referrals->awardFirstPurchaseBonus($order, $operation);
        $this->commissions->awardForOrder($order, $operation);
    }

    /**
     * تلاش مجدد برای سفارشی که مالی‌اش انجام شده ولی اکانتش ساخته نشده
     * (بند ۲۵ و ۲۹).
     *
     * عمداً هیچ کسر جدیدی انجام نمی‌شود — پول قبلاً گرفته شده. این تنها
     * مسیر درست برای جبران است؛ فراخوانی دوباره‌ی purchase() یعنی کسر
     * دوباره از مشتری.
     */
    public function retryProvisioning(Order $order, bool $force = false): Account
    {
        if ($order->isRenewal()) {
            throw new PurchaseNotAllowedException(
                "سفارش #{$order->id} یک سفارش تمدید است؛ تلاش مجدد آن از مسیر RenewalService::retry انجام می‌شود."
            );
        }

        // رزرو اتمیک — دو retry هم‌زمان ممکن نیست
        if (! $this->provisioning->claimForRetry($order, $force)) {
            throw new PurchaseNotAllowedException(
                "سفارش #{$order->id} قابل تلاش مجدد نیست (وضعیت: {$order->status}، تلاش‌ها: {$order->provision_attempts})."
            );
        }

        try {
            $account = $this->provisioning->provision($order);
        } catch (ProvisioningFailedException $e) {
            throw $e->withOutcome($this->failures->handle($order));
        } catch (\Throwable $e) {
            // خطای غیرمنتظره هم نباید سفارش را در provisioning رها کند
            $this->provisioning->recordFailure($order->fresh(), $e->getMessage());

            throw (new ProvisioningFailedException(
                "خطای غیرمنتظره در ساخت اکانت: {$e->getMessage()}",
                previous: $e
            ))->withOutcome($this->failures->handle($order));
        }

        // سفارشی که با retry نجات پیدا کرده باید مثل خریدِ موفق از اولین
        // تلاش رفتار کند: پاداش/کمیسیون (هر دو idempotent‌اند).
        $this->awardBenefits($order->fresh());

        return $account;
    }
}
