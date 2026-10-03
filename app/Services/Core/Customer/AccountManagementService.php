<?php

namespace App\Services\Core\Customer;

use App\Models\Account;
use App\Models\Operation;
use App\Services\Core\Renewal\RenewalQuote;
use App\Services\Core\Renewal\RenewalService;

/**
 * B3.2 — Service Management: مشاهده‌ی مصرف و تمدید یک سرویس مشتری.
 *
 * Core است (T3: ربات هم می‌تواند از همین بخواند)؛ Website فقط Adapter است. این کلاس هیچ منطق مالی
 * ندارد: تمدید همان RenewalService (Order، Debit، Operation، Retry، سیاست شکست) است و
 * این‌جا فقط «تصویر» سرویس ساخته می‌شود.
 *
 * مالکیت (آیا این سرویس مال همین مشتری در همین Context است؟) بر عهده‌ی فراخواننده است
 * (Controller با customer_account_id)؛ همان قرارداد AccountsController قبل از B3.2.
 *
 * تصمیم محصول (صاحب پروژه): «ارتقا/تغییر پلن» در این فاز وجود ندارد؛ تمدید همیشه روی همان تعرفه است.
 */
class AccountManagementService
{
    public function __construct(
        protected RenewalService $renewal,
        protected AccountUsageService $usage,
    ) {}

    public function overview(Account $account): ServiceOverview
    {
        $account->loadMissing(['product', 'customerAccount']);

        return new ServiceOverview(
            account: $account,
            isLapsed: $account->displayState() === 'expired',
            remainingDays: $account->remainingDays(),
            usagePercent: $account->trafficUsagePercent(),
            trafficTone: $account->trafficTone(),
            usageSyncedAt: $account->usage_synced_at,
            renewal: $this->renewal->quote($account),
        );
    }

    /** پیش‌فاکتور تمدید (فقط‌خواندنی؛ هیچ Wallet/Order نمی‌سازد). */
    public function quote(Account $account): RenewalQuote
    {
        return $this->renewal->quote($account);
    }

    /** موجودی کیف‌پولِ همین سرویس در همین Context (بدون ساختن Wallet). */
    public function balance(Account $account): int
    {
        $account->loadMissing('customerAccount');

        return $account->customerAccount ? $this->renewal->readBalance($account->customerAccount) : 0;
    }

    /**
     * آیا این کلید Idempotency قبلاً با موفقیت اجرا شده؟ (ارسال دوباره‌ی همان فرم). در این حالت
     * RenewalService چیزی کسر نمی‌کند و UI نباید به‌خاطر موجودیِ «بعد از کسر» خطای کمبود نشان بدهد.
     */
    public function isReplay(string $idempotencyKey): bool
    {
        return Operation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->exists();
    }

    public function refreshUsage(Account $account): UsageRefreshResult
    {
        return $this->usage->refresh($account);
    }

    public function renew(Account $account, string $idempotencyKey): Account
    {
        return $this->renewal->renew($account, $idempotencyKey);
    }
}
