<?php

namespace App\Services\Admin\Finance;

/**
 * یک نماینده‌ی بدهکار (B7.3): موجودی منفی Wallet Main صاحب او (W3). مبالغ int (Minor Unit).
 * قاعده‌ی قدرت خرید همان `Reseller::minimumBalance()` / B5.1 است: موجودی + سقف بدهی.
 */
final class ResellerDebtor
{
    public function __construct(
        public readonly int $resellerId,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly bool $active,
        public readonly int $balance,
        public readonly int $debtLimit,
    ) {}

    public function debt(): int
    {
        return $this->balance < 0 ? -$this->balance : 0;
    }

    public function buyingPower(): int
    {
        return $this->balance + $this->debtLimit;
    }

    /** بیش از سقف بدهکار است (قدرت خرید منفی) */
    public function isOverLimit(): bool
    {
        return $this->buyingPower() < 0;
    }

    /** مصرف سقف بدهی (٪، صحیح و گرد)؛ سقف صفر ⇒ null */
    public function limitUsagePercent(): ?int
    {
        if ($this->debtLimit <= 0) {
            return null;
        }

        return intdiv($this->debt() * 100 + intdiv($this->debtLimit, 2), $this->debtLimit);
    }
}
