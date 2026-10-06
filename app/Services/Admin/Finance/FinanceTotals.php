<?php

namespace App\Services\Admin\Finance;

/**
 * جمع‌های مالی یک بازه برای کل پلتفرم (B7.3). همه int (Minor Unit) یا شمارش؛ هیچ float مالی نیست.
 *
 * دو دسته:
 *  - سود و زیان (از سفارش‌های فروش قطعی، هم‌تعریف B7.1): فروش، درآمد پلتفرم، سود نمایندگان، هزینه‌ی معرفی.
 *  - جریان نقد (از Ledger کیف‌پول‌های Main، بر پایه‌ی زمان تراکنش): ورودی، بازگشت پرداخت، اصلاح دستی.
 *
 * تعریف‌ها در `GlobalFinanceService` و ADMIN-GLOBAL-FINANCE-CONTRACT.md.
 */
final class FinanceTotals
{
    public function __construct(
        /** سفارش‌های فروش قطعی (paid/account_created؛ بدون اکانت تست) */
        public readonly int $orders,
        /** فروش = آنچه مشتریان پرداخته‌اند (main_price یا customers_price) */
        public readonly int $sales,
        /** درآمد پلتفرم = آنچه به ما می‌رسد (main_price یا reseller_price) */
        public readonly int $platformRevenue,
        /** درآمد پلتفرم از فروش مستقیم (فروشگاه اصلی) */
        public readonly int $directRevenue,
        /** کمیسیون واریزشده به کیف‌پول‌های Main (هزینه‌ی پلتفرم) */
        public readonly int $commissions,
        /** پاداش معرفی واریزشده به کیف‌پول‌های Main (هزینه‌ی پلتفرم) */
        public readonly int $referralBonuses,
        /** بازگشت وجه سفارش به کیف‌پول‌های Main در بازه (سهم پلتفرم؛ بر پایه‌ی زمان بازگشت) */
        public readonly int $orderRefunds,
        public readonly int $refundedOrders,
        /** ورودی نقد پلتفرم: شارژ تأییدشده‌ی کیف‌پول‌های Main */
        public readonly int $cashIn,
        public readonly int $cashInCount,
        /** بازگشت پرداخت (پول شارژ به پرداخت‌کننده برگشت) از کیف‌پول‌های Main */
        public readonly int $paymentRefunds,
        /** خالص اصلاح دستی مدیر روی کیف‌پول‌های Main (علامت‌دار؛ نه درآمد است نه هزینه) */
        public readonly int $manualAdjustments,
        /** شارژ کیف‌پول مشتریانِ فروشگاه‌های نمایندگان (صف نمایندگان؛ پول پلتفرم نیست) */
        public readonly int $resellerStoreCashIn,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /** درآمد پلتفرم از هزینه‌ی تأمین فروش نمایندگان (reseller_price) */
    public function supplyRevenue(): int
    {
        return $this->platformRevenue - $this->directRevenue;
    }

    /** سود نمایندگان = فروش − درآمد پلتفرم؛ درآمد ما نیست */
    public function resellerMargin(): int
    {
        return $this->sales - $this->platformRevenue;
    }

    /** هزینه‌ی معرفی پلتفرم = کمیسیون + پاداش معرفی در کیف‌پول‌های Main */
    public function referralCost(): int
    {
        return $this->commissions + $this->referralBonuses;
    }

    /**
     * سود خالص تخمینی پلتفرم = درآمد پلتفرم − هزینه‌ی معرفی. می‌تواند منفی باشد.
     * «تخمینی» است: هزینه‌ی سرور/درگاه در سیستم ثبت نمی‌شود و هدیه‌ی کیف‌پولی وقتی هزینه‌ی واقعی است که خرج شود.
     * بازگشت وجه سفارش کسر نمی‌شود، چون سفارش بازگشت‌خورده از ابتدا در فروش قطعی نیست (دوباره‌شماری نمی‌شود).
     */
    public function platformNet(): int
    {
        return $this->platformRevenue - $this->referralCost();
    }

    /** حاشیه‌ی سود خالص تخمینی نسبت به درآمد پلتفرم (٪، صحیح و گرد متقارن)؛ درآمد صفر ⇒ null */
    public function platformNetMarginPercent(): ?int
    {
        return self::percent($this->platformNet(), $this->platformRevenue);
    }

    /** سهم فروش نمایندگی از درآمد پلتفرم (٪)؛ درآمد صفر ⇒ null */
    public function supplySharePercent(): ?int
    {
        return self::percent($this->supplyRevenue(), $this->platformRevenue);
    }

    /** خالص ورودی نقد = ورودی − بازگشت پرداخت */
    public function netCashIn(): int
    {
        return $this->cashIn - $this->paymentRefunds;
    }

    /** میانگین هر شارژ تأییدشده (گرد به نزدیک‌ترین)؛ بدون شارژ ⇒ ۰ */
    public function averageCharge(): int
    {
        return $this->cashInCount > 0
            ? intdiv($this->cashIn + intdiv($this->cashInCount, 2), $this->cashInCount)
            : 0;
    }

    public function isEmpty(): bool
    {
        return $this->orders === 0
            && $this->cashIn === 0
            && $this->paymentRefunds === 0
            && $this->orderRefunds === 0
            && $this->referralCost() === 0
            && $this->manualAdjustments === 0
            && $this->resellerStoreCashIn === 0;
    }

    private static function percent(int $part, int $base): ?int
    {
        if ($base <= 0) {
            return null;
        }

        // گرد به نزدیک‌ترین با حساب صحیح؛ برای عدد منفی متقارن (هم‌قاعده با FinanceStatement B5.5)
        $scaled = $part * 100;
        $half = intdiv($base, 2);

        return $scaled >= 0 ? intdiv($scaled + $half, $base) : -intdiv(-$scaled + $half, $base);
    }
}
