<?php

namespace App\Services\Core\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

/** یک سبد فروش با تعرفه‌های نمایش‌داده‌شده‌اش (B4.1) */
final class CatalogCategory
{
    /** @param  Collection<int, CatalogItem>  $items */
    public function __construct(
        public readonly Category $category,
        public readonly Collection $items,
    ) {}
}
