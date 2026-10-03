<?php

namespace App\Services\Core\Identity;

use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * منطق Email Authentication در Core (EMAIL-AUTH-CONTRACT.md §E2–E4).
 *
 * Website فقط Channel است: لینک/فرم را هماهنگ می‌کند و تصمیم اینجاست.
 */
class EmailAuthService
{
    public function __construct(
        protected AuditService $audit,
        protected SessionSecurityService $sessions,
    ) {}

    /**
     * E2: پس از Reset موفق (Token معتبر ⇒ کنترل صندوق Email اثبات شده):
     *  - رمز جدید (برای User بدون رمز، مثل ساخته‌شده با Google، «اولین رمز» است — G19)؛
     *  - remember_token عوض می‌شود؛
     *  - Email تأییدنشده ⇒ تأییدشده (اثبات مالکیت صندوق)؛
     *  - همه‌ی Sessionهای دیگر User باطل می‌شوند (ضد Pre-hijack: Session مهاجمی که با Email قربانی
     *    ثبت‌نام کرده بود نمی‌ماند)؛
     *  - Audit: `identity.password_reset` (+ `identity.email_verified` با method = password_reset).
     */
    public function completePasswordReset(User $user, string $plainPassword): void
    {
        $hadPassword = $user->getRawOriginal('password') !== null;
        $wasVerified = $user->hasVerifiedEmail();

        $user->forceFill([
            'password' => Hash::make($plainPassword),
            'remember_token' => Str::random(60),
        ])->save();

        if (! $wasVerified) {
            $user->markEmailAsVerified();
        }

        $this->revokeSessions($user);

        $this->audit->record('identity.password_reset', $user, after: ['first_password' => ! $hadPassword], actor: $user);

        if (! $wasVerified) {
            $this->audit->record('identity.email_verified', $user, after: ['method' => 'password_reset'], actor: $user);
        }
    }

    /** E3: تأیید با لینک امضاشده؛ فقط اگر واقعاً از «تأییدنشده» به «تأییدشده» رفت Audit می‌شود (Idempotent). */
    public function recordLinkVerification(User $user, bool $wasVerified): void
    {
        if (! $wasVerified && $user->hasVerifiedEmail()) {
            $this->audit->record('identity.email_verified', $user, after: ['method' => 'link'], actor: $user);
        }
    }

    /**
     * E4: ابطال همه‌ی Sessionهای User. فقط با Session Driver = database ممکن است؛
     * با Driver دیگر (file/redis) این مرحله No-op است و فقط remember_token عوض می‌شود
     * (و AuthenticateSession نشست‌های دیگر را با مقایسه‌ی Hash رمز می‌بندد).
     * B2.5: پیاده‌سازی به SessionSecurityService منتقل شد؛ این متد برای سازگاری می‌ماند.
     */
    public function revokeSessions(User $user): int
    {
        return $this->sessions->revokeAll($user);
    }
}
