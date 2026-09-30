<?php

namespace App\Services\Core\Store;

use App\Models\User;

/**
 * Master 2.7 G11 / Website Contract §6 (D-7، D-8) — Gate تأیید Email.
 *
 * فقط دو عملیات را مسدود می‌کند: Purchase و Wallet Charge (Direct Payment و
 * Card-to-Card). Enforcement در Core است (PurchaseService::purchase و
 * PaymentService::initiate)، نه Middleware Website؛ پس Bot/Admin هم از آن
 * عبور نمی‌کنند مگر شرط زیر برقرار نباشد.
 *
 * تفسیر Implementation (نیازمند تأیید صاحب پروژه — ر.ک. PHASE-4 §۵):
 * Gate فقط برای Userی اعمال می‌شود که **Email دارد ولی Verify نکرده**.
 * Userی که اصلاً Email ندارد (مثلاً Userهای ساخته‌شده توسط Telegram Bot،
 * هویت‌شان با Telegram ID ثابت است) مشمول Gate نیست؛ وگرنه کل خرید ربات
 * متوقف می‌شد. Email واردشده در Guest Form هیچ‌گاه Verified تلقی نمی‌شود (R9).
 */
class EmailVerificationGate
{
    public function requiresVerification(User $user): bool
    {
        return filled($user->email) && $user->email_verified_at === null;
    }

    /**
     * @throws EmailNotVerifiedException
     */
    public function assertVerified(User $user): void
    {
        if ($this->requiresVerification($user)) {
            throw new EmailNotVerifiedException;
        }
    }
}
