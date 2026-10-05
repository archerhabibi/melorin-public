<?php

namespace App\Services\Resellers\Dashboard;

/**
 * یک «نیازمند توجه» برای نماینده. مشتق‌شده از وضعیت زنده است (ذخیره نمی‌شود)، پس با رفع مشکل ناپدید می‌شود.
 *
 * Core فقط «هدف» را نوع می‌دهد؛ ساخت URL کار Channel (Filament) است — Core هیچ Route نمی‌شناسد.
 */
final class ResellerAlert
{
    public const TONE_DANGER = 'danger';

    public const TONE_WARNING = 'warning';

    public const TONE_INFO = 'info';

    public const TARGET_PAYMENTS = 'payments';

    public const TARGET_ATTENTION_ORDERS = 'attention_orders';

    public const TARGET_IN_PROGRESS_ORDERS = 'in_progress_orders';

    public const TARGET_EXPIRING_SERVICES = 'expiring_services';

    public const TARGET_CREDIT = 'credit';

    public function __construct(
        public readonly string $key,
        public readonly string $tone,
        public readonly string $title,
        public readonly string $body,
        public readonly string $target,
        public readonly int $count = 0,
        /** مبالغ (Minor Unit، صحیح) که در `body` با جانشین `{name}` آمده‌اند؛ قالب‌بندی کار Channel است (M2). */
        public readonly array $amounts = [],
    ) {}

    /**
     * متن نهایی: Core فقط مبلغ خام می‌دهد و Channel با تابع قالب‌بندی خودش جانشین‌ها را پر می‌کند.
     *
     * @param  \Closure(int): string  $formatMoney
     */
    public function renderBody(\Closure $formatMoney): string
    {
        $replace = [];

        foreach ($this->amounts as $name => $minor) {
            $replace['{'.$name.'}'] = $formatMoney((int) $minor);
        }

        return strtr($this->body, $replace);
    }

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
