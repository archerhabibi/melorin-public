<?php

namespace App\Services\Resellers\Domains;

/**
 * خواندن رکوردهای TXT — پشت یک Interface تا تست‌ها (و در آینده Resolverهای دیگر) بدون DNS واقعی کار کنند.
 */
interface DnsTxtResolver
{
    /**
     * @return list<string> مقدار هر رکورد TXT (قطعه‌های یک رکورد به‌هم چسبانده‌شده)؛ خطا/نبودن ⇒ آرایه‌ی خالی
     */
    public function txt(string $name): array;
}
