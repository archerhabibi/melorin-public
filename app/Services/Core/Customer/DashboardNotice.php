<?php

namespace App\Services\Core\Customer;

/**
 * یک «اعلان» داشبورد. مشتق‌شده از وضعیت زنده‌ی Core است (نه ردیف ذخیره‌شده)؛
 * بنابراین وقتی وضعیت اصلاح شود (تمدید، شارژ، تأیید رسید) خودبه‌خود ناپدید می‌شود.
 *
 * Core فقط «هدف» را به‌صورت نوع+شناسه می‌گوید؛ ساخت URL کار Channel است
 * (Website Contract: Core هیچ route/URL نمی‌شناسد).
 */
final class DashboardNotice
{
    public const TONE_DANGER = 'danger';

    public const TONE_WARNING = 'warning';

    public const TONE_INFO = 'info';

    public const TARGET_ACCOUNT = 'account';

    public const TARGET_ORDER = 'order';

    public const TARGET_WALLET = 'wallet';

    public const TARGET_CHARGE = 'charge';

    public function __construct(
        public readonly string $key,
        public readonly string $tone,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $targetType = null,
        public readonly ?int $targetId = null,
    ) {}

    /** ترتیب نمایش: خطر → هشدار → اطلاع */
    public function severity(): int
    {
        return match ($this->tone) {
            self::TONE_DANGER => 0,
            self::TONE_WARNING => 1,
            default => 2,
        };
    }
}
