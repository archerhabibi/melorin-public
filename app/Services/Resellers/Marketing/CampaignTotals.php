<?php

namespace App\Services\Resellers\Marketing;

/** جمع‌های کمپین‌های پیام (Broadcast) همین نماینده (B5.6). */
final class CampaignTotals
{
    public function __construct(
        public readonly int $campaigns,
        public readonly int $recipients,
        public readonly int $sent,
        public readonly int $failed,
        public readonly int $active,
    ) {}

    /** نرخ تحویل (٪) = ارسال‌موفق / کل گیرنده‌ها؛ بدون گیرنده ⇒ null */
    public function deliveryPercent(): ?int
    {
        return $this->recipients <= 0 ? null : intdiv($this->sent * 100 + intdiv($this->recipients, 2), $this->recipients);
    }
}
