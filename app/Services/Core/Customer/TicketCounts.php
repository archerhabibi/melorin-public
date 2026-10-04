<?php

namespace App\Services\Core\Customer;

/** شمارنده‌ی تیکت‌های مشتری به تفکیک وضعیت (B3.4) */
final class TicketCounts
{
    public function __construct(
        public readonly int $open,
        public readonly int $answered,
        public readonly int $closed,
    ) {}

    public function total(): int
    {
        return $this->open + $this->answered + $this->closed;
    }

    /** باز = هنوز بسته نشده (منتظر ادمین یا منتظر مشتری) */
    public function active(): int
    {
        return $this->open + $this->answered;
    }
}
