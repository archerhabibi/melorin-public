<?php

namespace App\Services\Core\Catalog;

use Illuminate\Support\Collection;

/**
 * نتیجه‌ی کاتالوگ برای یک Context و یک پرس‌وجو (B4.1).
 *
 * `categories` فقط سبدهای دارای نتیجه را (بعد از فیلتر) دارد؛ `options` همیشه همه‌ی سبدهای **قابل‌نمایش** (بدون
 * اعمال فیلتر سبد/جست‌وجو) است تا منوی فیلتر با هر جست‌وجو خالی نشود.
 */
final class Catalog
{
    /**
     * @param  Collection<int, CatalogCategory>  $categories
     * @param  Collection<int, CatalogItem>  $items  نتیجه‌ی مرتب‌شده (برای مرتب‌سازی غیرپیش‌فرض، سراسری و بدون گروه)
     * @param  list<array{id: int, name: string, count: int}>  $options
     */
    public function __construct(
        public readonly CatalogQuery $query,
        public readonly Collection $items,
        public readonly Collection $categories,
        public readonly array $options,
        public readonly int $totalVisible,
        public readonly int $totalMatched,
    ) {}

    /** کاتالوگ اصلاً چیزی برای نمایش ندارد (نه به‌خاطر فیلتر) */
    public function isEmpty(): bool
    {
        return $this->totalVisible === 0;
    }

    /** فیلتر فعال است ولی چیزی پیدا نشد */
    public function hasNoMatches(): bool
    {
        return $this->totalVisible > 0 && $this->totalMatched === 0;
    }

    /** پیش‌فرض ⇒ گروه‌بندی بر اساس سبد؛ هر مرتب‌سازی دیگر ⇒ یک فهرست سراسری */
    public function isGrouped(): bool
    {
        return $this->query->sort === CatalogQuery::SORT_DEFAULT;
    }
}
