<?php

namespace App\Services\Admin\Dashboard;

use Carbon\CarbonImmutable;

/** یک روز از روند فروش پلتفرم (روزهای بدون فروش با صفر پر می‌شوند تا نمودار پیوسته بماند). */
final class ExecutiveTrendPoint
{
    public function __construct(
        public readonly CarbonImmutable $day,
        public readonly int $orders,
        public readonly int $sales,
        public readonly int $platformRevenue,
    ) {}
}
