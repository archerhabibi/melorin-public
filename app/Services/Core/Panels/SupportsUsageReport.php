<?php

namespace App\Services\Core\Panels;

use App\DataTransferObjects\PanelAccountResult;

/**
 * قرارداد اختیاری (مثل SupportsServerStatus؛ نه بخشی از PanelDriverInterface تا درایورهای
 * موجود/تست‌ها مجبور به تغییر نشوند — بند ۳۲ سند).
 *
 * فرمت پاسخ getAccount در هر پنل فرق دارد؛ «استخراج مصرف از آن» دانشِ درایور است نه Core.
 * AccountUsageService فقط همین یک متد را می‌شناسد.
 */
interface SupportsUsageReport
{
    /**
     * مصرف کل (آپلود + دانلود) به بایت از نتیجه‌ی موفق getAccount().
     * null یعنی پاسخ پنل مصرف را نداشت (نه «صفر») تا مقدار قبلی خراب نشود.
     */
    public function usedTrafficBytes(PanelAccountResult $result): ?int;
}
