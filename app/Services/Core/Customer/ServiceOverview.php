<?php

namespace App\Services\Core\Customer;

use App\Models\Account;
use App\Services\Core\Renewal\RenewalQuote;
use Carbon\CarbonInterface;

/**
 * B3.2 — تصویر کامل «یک سرویس» برای صفحه‌ی مدیریت سرویس (مصرف + تمدید).
 * فقط‌خواندنی و بدون URL (Core هیچ route نمی‌شناسد؛ مثل DashboardNotice).
 */
final class ServiceOverview
{
    public function __construct(
        public readonly Account $account,
        public readonly bool $isLapsed,
        public readonly ?int $remainingDays,
        public readonly ?int $usagePercent,
        public readonly string $trafficTone,
        public readonly ?CarbonInterface $usageSyncedAt,
        public readonly RenewalQuote $renewal,
    ) {}

    public function isUnlimitedTraffic(): bool
    {
        return $this->usagePercent === null;
    }

    public function isExpiringSoon(): bool
    {
        return ! $this->isLapsed
            && $this->remainingDays !== null
            && $this->remainingDays <= CustomerDashboardService::EXPIRING_DAYS;
    }

    /** expired | expiring | active | disabled | suspended | … (برچسب را UI می‌دهد). */
    public function stateKey(): string
    {
        return $this->account->displayState();
    }
}
