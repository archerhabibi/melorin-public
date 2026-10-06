<?php

namespace App\Services\Admin\Dashboard;

/** یک نماینده در جدول «برترین نمایندگان» بازه (B7.1). مبالغ int (Minor Unit). */
final class TopReseller
{
    public function __construct(
        public readonly int $resellerId,
        public readonly string $name,
        public readonly ?string $slug,
        public readonly int $orders,
        /** آنچه مشتریان نماینده پرداخته‌اند */
        public readonly int $sales,
        /** آنچه به پلتفرم رسیده (reseller_price) */
        public readonly int $platformRevenue,
    ) {}

    /** سود خودِ نماینده */
    public function margin(): int
    {
        return $this->sales - $this->platformRevenue;
    }
}
