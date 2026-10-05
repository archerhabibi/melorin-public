<?php

namespace App\Services\Resellers\Products;

/** نتیجه‌ی عملیات گروهی (B5.3): کدام محصولات اعمال شدند و کدام با چه دلیلی رد شدند. هیچ ردی بی‌صدا نیست. */
final class BulkPricingResult
{
    /**
     * @param  list<int>  $applied  شناسه‌ی محصولات اعمال‌شده
     * @param  array<int, string>  $skipped  شناسه‌ی محصول ⟵ دلیل
     */
    public function __construct(
        public readonly array $applied = [],
        public readonly array $skipped = [],
    ) {}

    public function appliedCount(): int
    {
        return count($this->applied);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
