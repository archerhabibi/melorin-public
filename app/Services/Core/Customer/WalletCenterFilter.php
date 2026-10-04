<?php

namespace App\Services\Core\Customer;

use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;

/**
 * B3.3 — فیلتر گردش حساب کیف‌پول (جهت، نوع، بازه‌ی تاریخ).
 *
 * ورودی کاربر (Query String) هرگز مستقیم به Query نمی‌رسد: `fromInput()` همه‌چیز را اعتبارسنجی و
 * پاک‌سازی می‌کند و مقدار نامعتبر را **بی‌صدا حذف** می‌کند (نه 422): فیلتر خراب نباید صفحه‌ی کیف‌پول را
 * از دسترس خارج کند. تاریخ‌ها میلادی `Y-m-d` هستند (همان تقویم نمایش گردش حساب).
 */
final class WalletCenterFilter
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public function __construct(
        public readonly ?string $direction = null,
        public readonly ?string $type = null,
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
    ) {}

    /** @param array<string, mixed> $input معمولاً `$request->query()` */
    public static function fromInput(array $input): self
    {
        $direction = $input['direction'] ?? null;
        $direction = in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true) ? $direction : null;

        $type = $input['type'] ?? null;
        $type = is_string($type) && array_key_exists($type, WalletTransaction::typeLabels()) ? $type : null;

        $from = self::parseDate($input['from'] ?? null)?->startOfDay();
        $to = self::parseDate($input['to'] ?? null)?->endOfDay();

        // «از» بعد از «تا» ⇒ جابه‌جا (نتیجه‌ی خالی‌ِ گیج‌کننده بدتر از اصلاح بی‌صدا است).
        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return new self($direction, $type, $from, $to);
    }

    public function isActive(): bool
    {
        return $this->direction !== null || $this->type !== null || $this->from !== null || $this->to !== null;
    }

    /**
     * فقط مقدارهای پاک‌سازی‌شده برای لینک صفحه‌بندی و مقدار اولیه‌ی فرم.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'direction' => $this->direction,
            'type' => $this->type,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
        ], fn (?string $v) => $v !== null);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        // 2026-02-31 را PHP به 2026-03-03 «گرد» می‌کند؛ باید رد شود.
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
