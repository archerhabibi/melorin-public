<?php

namespace App\Services\Core\Catalog;

/**
 * پرس‌وجوی کاتالوگ (B4.1) — ورودی عمومی و غیرقابل‌اعتماد (query string سایت) را **یک‌جا** پاک‌سازی می‌کند تا
 * هر Channel همان قواعد را داشته باشد. مقدار ناشناخته هرگز خطا نمی‌دهد؛ به پیش‌فرض برمی‌گردد (کاتالوگ عمومی است).
 */
final class CatalogQuery
{
    public const SORT_DEFAULT = 'default';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORT_DURATION = 'duration';

    public const SORT_TRAFFIC = 'traffic';

    public const SEARCH_MAX = 60;

    public function __construct(
        public readonly ?string $search = null,
        public readonly ?int $categoryId = null,
        public readonly string $sort = self::SORT_DEFAULT,
        public readonly bool $availableOnly = false,
    ) {}

    /** برچسب فارسی مرتب‌سازی‌ها (منبع واحد برای همه‌ی Channelها؛ کلید = مقدار پارامتر `sort`) */
    public static function sortLabels(): array
    {
        return [
            self::SORT_DEFAULT => 'پیش‌فرض',
            self::SORT_PRICE_ASC => 'ارزان‌ترین',
            self::SORT_PRICE_DESC => 'گران‌ترین',
            self::SORT_DURATION => 'بیشترین مدت',
            self::SORT_TRAFFIC => 'بیشترین حجم',
        ];
    }

    /** @param  array<string, mixed>  $input  معمولاً `$request->query()` */
    public static function fromInput(array $input): self
    {
        $search = $input['q'] ?? null;
        $search = is_string($search) ? CatalogText::clean($search, self::SEARCH_MAX) : '';

        $category = $input['category'] ?? null;
        $categoryId = (is_string($category) || is_int($category)) && ctype_digit((string) $category) && (string) $category !== '' && strlen((string) $category) <= 12
            ? (int) $category
            : null;

        $sort = $input['sort'] ?? null;
        $sort = is_string($sort) && array_key_exists($sort, self::sortLabels()) ? $sort : self::SORT_DEFAULT;

        $available = $input['available'] ?? null;
        $availableOnly = is_string($available) && in_array(strtolower($available), ['1', 'true', 'on', 'yes'], true);

        return new self($search === '' ? null : $search, $categoryId === 0 ? null : $categoryId, $sort, $availableOnly);
    }

    /** آیا فیلتری (غیر از مرتب‌سازی) فعال است؟ (برای نمایش «پاک‌کردن فیلتر» و پیام «نتیجه‌ای نیست») */
    public function isFiltered(): bool
    {
        return $this->search !== null || $this->categoryId !== null || $this->availableOnly;
    }

    /** پارامترهای غیرپیش‌فرض برای ساختن لینک (خالی = URL تمیز) */
    public function toParams(): array
    {
        return array_filter([
            'q' => $this->search,
            'category' => $this->categoryId,
            'sort' => $this->sort === self::SORT_DEFAULT ? null : $this->sort,
            'available' => $this->availableOnly ? '1' : null,
        ], fn ($v) => $v !== null);
    }
}
