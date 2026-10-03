<?php

namespace App\Services\Ops;

/**
 * نتیجه‌ی یک بررسی عملیاتی (فاز ۹). `detail` فقط برای CLI/Log است و هرگز
 * در پاسخ HTTP عمومی (/health/ready) برنگردانده می‌شود.
 */
final class CheckResult
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public readonly string $group,
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail = '',
    ) {}

    public static function ok(string $group, string $name, string $detail = ''): self
    {
        return new self($group, $name, self::OK, $detail);
    }

    public static function warn(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::WARN, $detail);
    }

    public static function fail(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::FAIL, $detail);
    }

    public function toArray(): array
    {
        return ['group' => $this->group, 'name' => $this->name, 'status' => $this->status, 'detail' => $this->detail];
    }
}
