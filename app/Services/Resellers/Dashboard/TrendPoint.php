<?php

namespace App\Services\Resellers\Dashboard;

use Carbon\CarbonImmutable;

/** یک روز از روند فروش (روزهای بدون فروش با صفر پر می‌شوند تا نمودار پیوسته بماند). */
final class TrendPoint
{
    public function __construct(
        public readonly CarbonImmutable $day,
        public readonly int $orders,
        public readonly int $revenue,
        public readonly int $profit,
    ) {}
}
