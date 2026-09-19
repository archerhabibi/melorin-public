<?php

namespace App\Services\Core\Affiliate;

use App\Models\AffiliateSetting;
use App\Models\Commission;
use App\Models\CustomerAccount;
use App\Models\Operation;
use App\Models\Order;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فاز F — پاداش معرفی (بند ۳۳ بلوپرینت).
 *
 * بند ۳۰: «Referral Bonus ≠ Commission». این دو عمداً دو کلاس جدا
 * هستند حتی با اینکه شباهت ظاهری دارند:
 *
 *   پاداش معرفی → یک‌بار، در اولین خرید کاربر معرفی‌شده، مبلغ ثابت،
 *                 به هر دو طرف (مشتری و معرف)
 *   کمیسیون     → هر خرید، درصدی، فقط به معرف
 *
 * قاطی‌کردنشان یعنی تغییر قانون یکی ناخواسته دیگری را عوض کند — و چون
 * هر دو پول واقعی به کیف‌پول واریز می‌کنند، این نوع اشتباه گران است.
 *
 * بند ۳۳ می‌گوید رفتار Refund برای پاداش معرفی «بعداً به‌عنوان Business
 * Rule مستقل تعیین می‌شود». تا آن موقع، مثل کمیسیون (بند ۳۲) پاداش
 * پرداخت‌شده برنمی‌گردد — این صریحاً یک تصمیم موقت است، نه فراموشی.
 */
class ReferralService
{
    public function __construct(
        protected WalletService $wallet,
        protected IdentityService $identity,
    ) {}

    /**
     * پاداش اولین خرید (بند ۱۳.۱ سند نیازمندی): مبلغ N به خود مشتری و
     * مبلغ B به معرف.
     *
     * مثل کمیسیون، هیچ خطایی به بیرون نشت نمی‌کند — پاداش نباید بتواند
     * خریدِ موفق را شکست‌خورده جلوه دهد.
     */
    public function awardFirstPurchaseBonus(Order $order, ?Operation $operation = null): void
    {
        try {
            $this->tryAward($order, $operation);
        } catch (\Throwable $e) {
            Log::error('referral_bonus_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function tryAward(Order $order, ?Operation $operation): void
    {
        $buyer = $order->customerAccount;

        if (! $buyer || $buyer->isGuest()) {
            return;
        }

        $referrerUser = $buyer->user?->referrer;

        if (! $referrerUser) {
            return;
        }

        if (! $this->isFirstPurchase($buyer, $order)) {
            return;
        }

        // یک‌بار برای همیشه، حتی اگر این متد دوباره صدا زده شود
        if (Commission::where('referred_user_id', $buyer->user_id)
            ->where('type', 'first_purchase_bonus')
            ->exists()) {
            return;
        }

        $settings = AffiliateSetting::current();
        $customerBonus = (float) $settings->customer_bonus_amount;
        $referrerBonus = (float) $settings->referrer_bonus_amount;

        if ($customerBonus <= 0 && $referrerBonus <= 0) {
            return;
        }

        $store = StoreContext::fromReseller($buyer->reseller);
        $referrerAccount = $this->identity->resolveCustomerAccount($referrerUser, $store);

        DB::transaction(function () use ($order, $buyer, $referrerUser, $referrerAccount, $customerBonus, $referrerBonus, $operation) {
            if ($referrerBonus > 0) {
                $record = Commission::create([
                    'referrer_id' => $referrerUser->id,
                    'referred_user_id' => $buyer->user_id,
                    'referrer_customer_account_id' => $referrerAccount->id,
                    'referred_customer_account_id' => $buyer->id,
                    'order_id' => $order->id,
                    'type' => 'first_purchase_bonus',
                    'base_amount' => (float) ($order->main_price ?? $order->customers_price),
                    'amount' => $referrerBonus,
                    'status' => 'paid',
                ]);

                $this->wallet->credit(
                    $referrerAccount,
                    $referrerBonus,
                    'referral_bonus',
                    $record,
                    "پاداش معرفی — اولین خرید زیرمجموعه (سفارش #{$order->id})",
                    $operation,
                );
            }

            if ($customerBonus > 0) {
                $this->wallet->credit(
                    $buyer,
                    $customerBonus,
                    'referral_bonus',
                    $order,
                    "پاداش اولین خرید — سفارش #{$order->id}",
                    $operation,
                );
            }
        });
    }

    /**
     * آیا این واقعاً اولین خرید این مشتری در این فروشگاه است؟
     *
     * محدود به همان CustomerAccount است، نه کل Identity: اگر کسی در
     * فروشگاه اصلی قبلاً خرید کرده و حالا اولین خریدش را نزد یک نماینده
     * انجام می‌دهد، از دید آن نماینده این یک مشتری تازه است. این دقیقاً
     * همان چیزی است که معماری Multi-Store فاز A ممکن کرد.
     */
    protected function isFirstPurchase(CustomerAccount $buyer, Order $order): bool
    {
        return ! Order::where('customer_account_id', $buyer->id)
            ->where('id', '!=', $order->id)
            ->whereIn('status', [
                Order::STATUS_PAID,
                Order::STATUS_PROVISIONING,
                Order::STATUS_ACCOUNT_CREATED,
                Order::STATUS_PROVISION_FAILED,
            ])
            ->exists();
    }
}
