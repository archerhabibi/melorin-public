<?php

namespace App\Services\Core\Customer;

/** B3.2 — نتیجه‌ی یک تلاش برای هم‌گام‌سازی مصرف یک سرویس از پنل. */
final class UsageRefreshResult
{
    public const REFRESHED = 'refreshed';

    /** اخیراً هم‌گام شده؛ پنل صدا زده نشد. */
    public const THROTTLED = 'throttled';

    /** درایور این پنل گزارش مصرف ندارد (یا نوع پنل ناشناخته). */
    public const UNSUPPORTED = 'unsupported';

    /** پنل در دسترس نبود یا پاسخ مصرف نداشت؛ عدد قبلی دست‌نخورده ماند. */
    public const FAILED = 'failed';

    /** سرویس فعال نیست؛ مصرف زنده معنا ندارد. */
    public const SKIPPED = 'skipped';

    private function __construct(public readonly string $status) {}

    public static function of(string $status): self
    {
        return new self($status);
    }

    public function refreshed(): bool
    {
        return $this->status === self::REFRESHED;
    }
}
