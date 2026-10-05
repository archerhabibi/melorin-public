<?php

namespace App\Services\Resellers\Products;

/** شمارنده‌های بالای فهرست محصولات نماینده (B5.3). همه عدد صحیح؛ مبلغ Minor Unit. */
final class ProductCatalogSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $selling,
        public readonly int $paused,
        public readonly int $unpriced,
        public readonly int $blocked,
        /** میانگین سود هر فروش روی محصولات در حال فروش؛ بدون محصول فعال null */
        public readonly ?int $averageProfit,
        /** تعداد محصولاتِ در حال فروش که سودشان صفر است (فروش بدون حاشیه) */
        public readonly int $zeroProfit,
    ) {}
}
