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
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فاز F — کمیسیون خریدهای زیرمجموعه (بند ۳۱ و ۳۲ بلوپرینت).
 *
 * بند ۳۰ صریح است: «Referral و Commission دو Feature جدا هستند. نباید
 * در مدل جدید یکی شوند.» به همین دلیل این کلاس فقط کمیسیونِ درصدی روی
 * خریدها را می‌سازد و پاداش ثبت‌نام کاملاً جای دیگری (ReferralService)
 * است. قاطی‌کردنشان یعنی تغییر قانون یکی، ناخواسته دیگری را هم عوض کند.
 *
 * دو تصمیم مهم:
 *
 * ۱) اسنپ‌شات نرخ (بند ۳۱): نرخ و مبنای محاسبه روی خودِ رکورد کمیسیون
 *    ذخیره می‌شوند، نه اینکه موقع گزارش از تنظیمات فعلی خوانده شوند.
 *
 * ۲) کمیسیون با بازگشت وجه برنمی‌گردد (بند ۳۲): «Purchase Refund →
 *    Commission NOT reversed». این یک تصمیم کسب‌وکاری است نه فراموشی،
 *    پس صریحاً اینجا نوشته شده تا کسی بعداً آن را به‌عنوان باگ «درست»
 *    نکند.
 */
class CommissionService
{
    public function __construct(
        protected WalletService $wallet,
        protected IdentityService $identity,
    ) {}

    /**
     * کمیسیون یک خرید را — در صورت وجود معرف واجد شرایط — محاسبه، ثبت
     * و به کیف‌پول معرف واریز می‌کند.
     *
     * عمداً هیچ استثنایی به بیرون نشت نمی‌کند: کمیسیون یک مزیت جانبی
     * است و شکستش هرگز نباید خریدی را که پولش گرفته شده و اکانتش ساخته
     * شده، شکست‌خورده جلوه دهد. خطا فقط لاگ می‌شود.
     */
    public function awardForOrder(Order $order, ?Operation $operation = null): ?Commission
    {
        try {
            return $this->tryAward($order, $operation);
        } catch (\Throwable $e) {
            Log::error('commission_award_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function tryAward(Order $order, ?Operation $operation): ?Commission
    {
        $buyer = $order->customerAccount;

        if (! $buyer || $buyer->isGuest()) {
            return null;
        }

        $referrerUser = $buyer->user?->referrer;

        if (! $referrerUser) {
            return null;
        }

        // idempotency: هر سفارش فقط یک بار کمیسیون درصدی می‌دهد.
        // first_purchase_bonus می‌تواند روی همان سفارش جداگانه وجود داشته باشد.
       if (Commission::where('order_id', $order->id)
            ->where('type', 'ongoing_commission')
            ->exists()) {
            return null;
        }

        $settings = AffiliateSetting::current();
        // درصد «نرخ» است نه پول: به‌صورت رشته‌ی دسیمال نگه داشته می‌شود (بدون float).
        $rate = (string) $settings->commission_percent;

        if (! is_numeric($rate) || $rate <= 0) {
            return null;
        }

        if (! $this->isWithinValidityWindow($buyer, $settings)) {
            return null;
        }

        // کمیسیون همیشه در همان فروشگاهی پرداخت می‌شود که خرید در آن
        // انجام شده (بند ۳۱ به‌علاوه‌ی معماری Multi-Store فاز A). معرف
        // ممکن است در فروشگاه اصلی هم حساب داشته باشد، ولی پولِ خریدِ
        // نمایندگی باید در کیف‌پول همان نمایندگی بنشیند — نه جای دیگر.
        $store = StoreContext::fromReseller($buyer->reseller);
        $referrerAccount = $this->identity->resolveCustomerAccount($referrerUser, $store);

        $base = (int) ($order->main_price ?? $order->customers_price);
        $amount = Money::percentOf($base, $rate); // Half-Up، کاملاً عدد صحیح

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $buyer, $referrerUser, $referrerAccount, $rate, $base, $amount, $operation) {
            $commission = Commission::create([
                'referrer_id' => $referrerUser->id,
                'referred_user_id' => $buyer->user_id,
                'referrer_customer_account_id' => $referrerAccount->id,
                'referred_customer_account_id' => $buyer->id,
                'order_id' => $order->id,
                'type' => 'ongoing_commission',
                'commission_rate' => $rate,
                'base_amount' => $base,
                'amount' => $amount,
                'status' => 'paid',
            ]);

            $this->wallet->credit(
                $referrerAccount,
                $amount,
                'commission',
                $commission,
                "کمیسیون خرید زیرمجموعه — سفارش #{$order->id}",
                $operation,
            );

            return $commission;
        });
    }

    /**
     * بند ۱۳ سند نیازمندی — «مدت اعتبار کمیسیون».
     *
     * اگر ادمین بازه‌ای تعیین کرده باشد، کمیسیون فقط تا آن مدت بعد از
     * پیوستن کاربر معرفی‌شده پرداخت می‌شود. صفر یا خالی یعنی بدون
     * محدودیت زمانی.
     */
    protected function isWithinValidityWindow(CustomerAccount $buyer, AffiliateSetting $settings): bool
    {
        $days = (int) ($settings->commission_validity_days ?? 0);

        if ($days <= 0) {
            return true;
        }

        $joinedAt = $buyer->user?->created_at;

        return ! $joinedAt || $joinedAt->diffInDays(now()) <= $days;
    }
}

