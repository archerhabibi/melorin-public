<?php

namespace App\Services\Resellers\Finance;

use App\Services\Resellers\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;

/**
 * صورت‌حساب یک بازه (B5.5): فروش، هزینه‌ی تأمین، سود ناخالص، هدیه‌های کیف‌پولی به مشتریان و سود خالص،
 * کنار جریان‌های نقدی و تعهد کیف‌پول مشتریان. همه int (Minor Unit)؛ درصدها صحیح و گرد به نزدیک‌ترین.
 *
 * سود خالص «تخمینی» است: هدیه‌ی کیف‌پولی (کمیسیون/پاداش) وقتی هزینه است که مشتری آن را خرج کند.
 */
final class FinanceStatement
{
    public function __construct(
        public readonly DashboardPeriod $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $orders,
        public readonly int $revenue,
        public readonly int $supplyCost,
        public readonly int $commissionsGiven,
        public readonly int $bonusesGiven,
        /** شارژ کیف‌پول مشتریانِ این فروشگاه در بازه (تأییدشده) */
        public readonly int $customerCharges,
        /** شارژ اعتبارِ خودِ نماینده در بازه (به Melorin) */
        public readonly int $ownerCharges,
        /** مجموع موجودی فعلی کیف‌پول مشتریان این فروشگاه (مستقل از بازه) */
        public readonly int $customerWalletBalances,
    ) {}

    public function grossProfit(): int
    {
        return $this->revenue - $this->supplyCost;
    }

    public function giftsGiven(): int
    {
        return $this->commissionsGiven + $this->bonusesGiven;
    }

    public function netProfit(): int
    {
        return $this->grossProfit() - $this->giftsGiven();
    }

    /** حاشیه‌ی سود ناخالص نسبت به فروش (٪)؛ فروش صفر/منفی ⇒ null */
    public function grossMarginPercent(): ?int
    {
        return self::percent($this->grossProfit(), $this->revenue);
    }

    public function netMarginPercent(): ?int
    {
        return self::percent($this->netProfit(), $this->revenue);
    }

    public function isEmpty(): bool
    {
        return $this->orders === 0 && $this->giftsGiven() === 0 && $this->customerCharges === 0 && $this->ownerCharges === 0;
    }

    private static function percent(int $part, int $base): ?int
    {
        if ($base <= 0) {
            return null;
        }

        // گرد به نزدیک‌ترین با حساب صحیح؛ برای عدد منفی هم متقارن (نیم از صفر دورتر)
        $scaled = $part * 100;
        $half = intdiv($base, 2);

        return $scaled >= 0 ? intdiv($scaled + $half, $base) : -intdiv(-$scaled + $half, $base);
    }
}
