<?php

namespace App\Services\Resellers\Products;

use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** پرونده‌ی قیمت‌گذاری یک محصول برای صفحه‌ی جزئیات (B5.3). */
final class ProductPricingProfile
{
    /**
     * @param  Collection<int, array{action: string, at: CarbonInterface, from: ?int, to: ?int}>  $history
     */
    public function __construct(
        public readonly Product $product,
        public readonly ProductSaleState $state,
        public readonly int $supplyPrice,
        public readonly ?int $customersPrice,
        public readonly bool $priceEnabled,
        public readonly ?ProductMargin $margin,
        public readonly PriceBounds $bounds,
        public readonly int $salesCount,
        public readonly int $salesRevenue,
        public readonly int $salesProfit,
        public readonly ?CarbonInterface $lastSoldAt,
        public readonly Collection $history,
    ) {}
}
