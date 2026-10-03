<?php

namespace App\Services\Core\Identity;

use Carbon\CarbonInterface;

/**
 * یک نشست فعال کاربر (B2.5). فقط داده‌ی خام؛ Core متن UI یا قالب‌بندی تولید نمی‌کند.
 *
 * `handle` شناسه‌ی مبهم (HMAC) نشست است، نه خودِ Session ID: Session ID مقدار Cookie است و هرگز
 * نباید داخل HTML/فرم برود (XSS یا اسکرین‌شات آن را لو می‌دهد).
 */
final class ActiveSession
{
    public function __construct(
        public readonly string $handle,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly CarbonInterface $lastActivity,
        public readonly bool $isCurrent,
    ) {}
}
