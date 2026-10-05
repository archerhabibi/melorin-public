<?php

namespace App\Services\Core\Customer;

/**
 * نمای پیگیری یک سفارش برای مشتری (B4.5) — فقط‌خواندنی و بدون وابستگی به Channel.
 *
 * مراحل: ثبت ← پرداخت ← ساخت سرویس (یا اعمال تمدید) ← تحویل. هر مرحله یکی از
 * done | current | failed | upcoming است. `headline/message/tone` متن و لحن وضعیت فعلی‌اند تا هیچ View
 * و هیچ Channel دیگری (ربات) منطق وضعیت را کپی نکند. مقدار `failure_reason` داخلی هرگز اینجا نمی‌آید.
 */
final class OrderTracking
{
    /**
     * @param  list<array{label: string, state: string}>  $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly string $headline,
        public readonly string $message,
        public readonly string $tone,          // success | info | warning | danger | neutral
        public readonly bool $inProgress,      // هنوز تمام نشده؛ صفحه می‌تواند خودش را تازه کند
        public readonly bool $needsSupport,    // پول گرفته شده/مسئله‌ای هست که پشتیبانی باید ببیند
        public readonly bool $isRenewal,
        public readonly bool $autoRetry,       // سامانه خودش دوباره تلاش می‌کند
        public readonly ?\DateTimeInterface $nextRetryAt,
    ) {}

    /**
     * شماره‌ی مرحله‌ی جاری (۱..N) برای `x-ui.steps`: اولین مرحله‌ای که هنوز done نیست (جاری، شکست‌خورده،
     * یا اولین مرحله‌ی انجام‌نشده‌ی سفارش بسته‌شده مثل بازگشت وجه)؛ همه done ⇒ آخرین مرحله.
     */
    public function currentStep(): int
    {
        foreach ($this->steps as $i => $step) {
            if ($step['state'] !== 'done') {
                return $i + 1;
            }
        }

        return count($this->steps);
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_column($this->steps, 'label');
    }
}
