<?php

namespace App\Services\Core\Provisioning;

/** نتیجه‌ی یک تلاش مجدد یا بازگشت دستی؛ برای پنل و Command. */
final class RecoveryResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $outcome = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
