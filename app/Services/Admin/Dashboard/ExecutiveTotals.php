<?php

namespace App\Services\Admin\Dashboard;

/**
 * جمع‌های یک بازه برای کل پلتفرم (B7.1). همه int (Minor Unit) یا شمارش؛ هیچ float مالی نیست.
 *
 * تعریف‌ها در `ExecutiveDashboardService` و Contract (`ADMIN-EXECUTIVE-DASHBOARD-CONTRACT.md`) است.
 */
final class ExecutiveTotals
{
    public function __construct(
        /** سفارش‌های فروش قطعی (paid/account_created؛ بدون اکانت تست) */
        public readonly int $orders,
        public readonly int $renewals,
        /** فروش = آنچه مشتری پرداخته (main_price یا customers_price) */
        public readonly int $sales,
        /** درآمد پلتفرم = آنچه به ما می‌رسد (main_price یا reseller_price) */
        public readonly int $platformRevenue,
        public readonly int $directSales,
        public readonly int $resellerSales,
        public readonly int $resellerOrders,
        /** سفارش‌های ناموفق: failed + provision_failed (بدون اکانت تست) */
        public readonly int $failedOrders,
        public readonly int $newUsers,
        /** سرویس‌های ساخته‌شده‌ی غیرآزمایشی */
        public readonly int $newServices,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    /** خریدهای جدید = سفارش‌ها منهای تمدیدها */
    public function purchases(): int
    {
        return $this->orders - $this->renewals;
    }

    /** سودِ نمایندگان = فروش منهای درآمد پلتفرم (برای فروش مستقیم صفر است) */
    public function resellerMargin(): int
    {
        return $this->sales - $this->platformRevenue;
    }

    /** میانگین مبلغ سفارش (تقسیم صحیح؛ بدون سفارش ⇒ ۰) */
    public function averageOrder(): int
    {
        return $this->orders > 0 ? intdiv($this->sales, $this->orders) : 0;
    }

    /** سهم فروش نمایندگی از کل فروش، درصد صحیح گردشده (بدون فروش ⇒ ۰) */
    public function resellerSharePercent(): int
    {
        return $this->sales > 0 ? intdiv($this->resellerSales * 100 + intdiv($this->sales, 2), $this->sales) : 0;
    }

    /**
     * نرخ شکست به «در هزار» (۱۲ ⇒ ۱٫۲٪)، گردشده؛ عدد صحیح تا هیچ float لازم نباشد.
     * مخرج = سفارش‌هایی که به نتیجه رسیده‌اند (فروش قطعی + ناموفق).
     */
    public function failureRatePerMille(): int
    {
        $total = $this->orders + $this->failedOrders;

        return $total > 0 ? intdiv($this->failedOrders * 1000 + intdiv($total, 2), $total) : 0;
    }
}
