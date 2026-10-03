<?php

namespace App\Services\Core\Identity;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Core\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * B2.4 — Account Linking برای User **واردشده** (Google / Telegram) در Core.
 * قرارداد: docs/canonical/ACCOUNT-LINKING-CONTRACT.md.
 *
 * تفاوت با ورود (`ExternalIdentityService`): آنجا هویت بیرونی به User «Resolve» می‌شود؛ اینجا User
 * از قبل احراز شده و صریحاً می‌خواهد روش دیگری به همان حساب وصل/جدا کند. بنابراین Email
 * تطبیق‌دهنده نیست (Email Google می‌تواند با Email حساب فرق کند)؛ کلید فقط `(provider, sub)` است (G13).
 *
 * قواعد ثابت:
 *  - هرگز Merge: هویتی که به User دیگری وصل است رد می‌شود و Audit می‌شود.
 *  - هر User حداکثر یک Google و یک Telegram؛ جایگزینی بی‌صدا ممنوع (اول Unlink).
 *  - Lock-out ممنوع: Unlink فقط وقتی مجاز است که حداقل یک روش ورود Website بماند
 *    (رمز عبور یا Google). روش‌های ورود: {password, google}. Telegram روش ورود Website نیست.
 *  - هیچ CustomerAccount/Wallet/Order ای ساخته نمی‌شود (R7).
 *  - Audit بدون PII (Email/Token نمی‌آید).
 */
class AccountLinkingService
{
    public function __construct(
        protected AuditService $audit,
        protected SessionSecurityService $sessions,
    ) {}

    // ───────────────────────── وضعیت ─────────────────────────

    public function hasPassword(User $user): bool
    {
        $raw = $user->getRawOriginal('password');

        return $raw !== null && $raw !== '';
    }

    public function googleIdentity(User $user): ?UserIdentity
    {
        return $user->identities()->where('provider', UserIdentity::PROVIDER_GOOGLE)->first();
    }

    /** تعداد روش‌های ورود Website که الان فعال‌اند. */
    public function loginMethodCount(User $user): int
    {
        return ($this->hasPassword($user) ? 1 : 0)
            + ($this->googleIdentity($user) ? 1 : 0);
    }

    // ───────────────────────── Google ─────────────────────────

    public function linkGoogle(User $user, string $subject, string $email, bool $emailVerified): AccountLinkResult
    {
        if ($user->status !== 'active') {
            return $this->rejectGoogle($user, AccountLinkResult::REASON_USER_NOT_ACTIVE);
        }

        if (! $emailVerified) {
            return $this->rejectGoogle($user, AccountLinkResult::REASON_PROVIDER_EMAIL_NOT_VERIFIED);
        }

        $owner = UserIdentity::query()
            ->where('provider', UserIdentity::PROVIDER_GOOGLE)
            ->where('provider_user_id', $subject)
            ->first();

        if ($owner) {
            if ((int) $owner->user_id === (int) $user->id) {
                return AccountLinkResult::already();
            }

            return $this->rejectGoogle($user, AccountLinkResult::REASON_OWNED_BY_OTHER, ['owned_by_user_id' => $owner->user_id]);
        }

        if ($this->googleIdentity($user)) {
            return $this->rejectGoogle($user, AccountLinkResult::REASON_ALREADY_HAS_PROVIDER);
        }

        try {
            UserIdentity::create([
                'user_id' => $user->id,
                'provider' => UserIdentity::PROVIDER_GOOGLE,
                'provider_user_id' => $subject,
                'provider_email' => Str::lower(trim($email)),
                'last_login_at' => now(),
            ]);
        } catch (QueryException) {
            // Race روی Unique Index (دو Callback هم‌زمان یا هم‌زمان با ورود Google کاربر دیگر).
            return $this->rejectGoogle($user, AccountLinkResult::REASON_RACE);
        }

        $this->audit->record('identity.google_linked', $user, after: ['method' => 'profile'], actor: $user);

        return AccountLinkResult::ok();
    }

    public function unlinkGoogle(User $user): AccountLinkResult
    {
        $identity = $this->googleIdentity($user);

        if (! $identity) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_NOT_LINKED);
        }

        // Google تنها روش ورود است ⇒ جدا کردنش حساب را قفل می‌کند.
        if (! $this->hasPassword($user)) {
            $this->audit->record('identity.google_unlink_rejected', $user, after: ['reason' => AccountLinkResult::REASON_LAST_LOGIN_METHOD], actor: $user);

            return AccountLinkResult::rejected(AccountLinkResult::REASON_LAST_LOGIN_METHOD);
        }

        $identity->delete();

        $this->audit->record('identity.google_unlinked', $user, actor: $user);

        return AccountLinkResult::ok();
    }

    // ───────────────────────── Telegram ─────────────────────────

    public function linkTelegram(User $user, int $telegramId, ?string $displayName = null): AccountLinkResult
    {
        if ($user->status !== 'active') {
            return $this->rejectTelegram($user, AccountLinkResult::REASON_USER_NOT_ACTIVE);
        }

        if ($user->telegram_id !== null) {
            // همان تلگرام ⇒ Idempotent. تلگرام دیگر ⇒ رد (قبلاً بی‌صدا جایگزین می‌شد).
            return (int) $user->telegram_id === $telegramId
                ? AccountLinkResult::already()
                : $this->rejectTelegram($user, AccountLinkResult::REASON_ALREADY_HAS_PROVIDER);
        }

        $owner = User::withTrashed()->where('telegram_id', $telegramId)->first();

        if ($owner && (int) $owner->id !== (int) $user->id) {
            // Master G10 / بند ۸۷: حتی با HMAC معتبر، Merge/جابه‌جایی خودکار ممنوع.
            return $this->rejectTelegram($user, AccountLinkResult::REASON_OWNED_BY_OTHER, ['telegram_id' => $telegramId, 'owned_by_user_id' => $owner->id]);
        }

        try {
            $user->update([
                'telegram_id' => $telegramId,
                'full_name' => $user->full_name ?? ($displayName !== null && trim($displayName) !== '' ? trim($displayName) : null),
            ]);
        } catch (QueryException) {
            return $this->rejectTelegram($user, AccountLinkResult::REASON_RACE);
        }

        $this->audit->record('identity.telegram_linked', $user, after: ['telegram_id' => $telegramId], actor: $user);

        return AccountLinkResult::ok();
    }

    /**
     * Unlink فقط وقتی مجاز است که User بتواند با Website کار کند: Email دارد و حداقل یک روش ورود
     * (رمز/Google). User ربات (بدون Email) با Unlink از حساب و Wallet خودش بیرون می‌ماند.
     * توجه: پس از Unlink، `/start` در ربات برای این Telegram ID یک User جدید می‌سازد (Wallet
     * قبلی در حساب Website می‌ماند و Merge وجود ندارد) — در UI هشدار داده می‌شود.
     */
    public function unlinkTelegram(User $user): AccountLinkResult
    {
        if ($user->telegram_id === null) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_NOT_LINKED);
        }

        if (! $user->email || $this->loginMethodCount($user) === 0) {
            $this->audit->record('identity.telegram_unlink_rejected', $user, after: ['reason' => AccountLinkResult::REASON_LAST_LOGIN_METHOD], actor: $user);

            return AccountLinkResult::rejected(AccountLinkResult::REASON_LAST_LOGIN_METHOD);
        }

        $old = (int) $user->telegram_id;

        $user->forceFill(['telegram_id' => null])->save();

        $this->audit->record('identity.telegram_unlinked', $user, after: ['telegram_id' => $old], actor: $user);

        return AccountLinkResult::ok();
    }

    // ───────────────────────── Password (D-13) ─────────────────────────

    /**
     * اولین رمز برای User بدون رمز (مثلاً ساخته‌شده با Google) از Profile. Email باید تأییدشده باشد
     * (G11/G19). remember_token عوض می‌شود و (B2.5) نشست‌های دیگر باطل می‌شوند: کسی که تا الان فقط با
     * Google وارد شده بود، با تعیین رمز نباید نشست ناشناخته‌ای در حسابش بماند.
     */
    public function setFirstPassword(User $user, string $plainPassword, ?string $currentSessionId = null): AccountLinkResult
    {
        if ($this->hasPassword($user)) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_PASSWORD_ALREADY_SET);
        }

        if (! $user->hasVerifiedEmail()) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_PROVIDER_EMAIL_NOT_VERIFIED);
        }

        DB::transaction(function () use ($user, $plainPassword) {
            $user->forceFill([
                'password' => Hash::make($plainPassword),
                'remember_token' => Str::random(60),
            ])->save();
        });

        $this->audit->record('identity.password_set', $user, after: ['method' => 'profile'], actor: $user);

        $this->sessions->revokeOthers($user, $currentSessionId, 'password_set');

        return AccountLinkResult::ok();
    }

    /**
     * B2.5 — تغییر رمزِ موجود از Profile. رمز فعلی اینجا (نه فقط در Channel) تأیید می‌شود: تصمیم امنیتی
     * در Core است. موفقیت: remember_token عوض می‌شود، همه‌ی نشست‌های دیگر باطل می‌شوند (نشست فعلی
     * می‌ماند)، Audit `identity.password_changed`. رمز و Hash هرگز در Audit/Log نمی‌آیند.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, ?string $currentSessionId = null): AccountLinkResult
    {
        if (! $this->hasPassword($user)) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_NO_PASSWORD);
        }

        if (! Hash::check($currentPassword, (string) $user->password)) {
            $this->audit->record('identity.password_change_rejected', $user, after: ['reason' => AccountLinkResult::REASON_WRONG_PASSWORD], actor: $user);

            return AccountLinkResult::rejected(AccountLinkResult::REASON_WRONG_PASSWORD);
        }

        if (Hash::check($newPassword, (string) $user->password)) {
            return AccountLinkResult::rejected(AccountLinkResult::REASON_PASSWORD_UNCHANGED);
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->forceFill([
                'password' => Hash::make($newPassword),
                'remember_token' => Str::random(60),
            ])->save();
        });

        $this->audit->record('identity.password_changed', $user, after: ['method' => 'profile'], actor: $user);

        $this->sessions->revokeOthers($user, $currentSessionId, 'password_changed');

        return AccountLinkResult::ok();
    }

    // ───────────────────────── Helpers ─────────────────────────

    protected function rejectGoogle(User $user, string $reason, array $extra = []): AccountLinkResult
    {
        $this->audit->record('identity.google_link_rejected', $user, after: ['reason' => $reason] + $extra, actor: $user);

        return AccountLinkResult::rejected($reason);
    }

    protected function rejectTelegram(User $user, string $reason, array $extra = []): AccountLinkResult
    {
        // نام Audit قدیمی برای «متعلق به دیگری» حفظ شد (تست‌ها و داشبوردها به آن وابسته‌اند).
        $action = $reason === AccountLinkResult::REASON_OWNED_BY_OTHER
            ? 'identity.telegram_link_rejected_owned_by_other'
            : 'identity.telegram_link_rejected';

        $this->audit->record($action, $user, after: ($reason === AccountLinkResult::REASON_OWNED_BY_OTHER ? [] : ['reason' => $reason]) + $extra, actor: $user);

        return AccountLinkResult::rejected($reason);
    }
}
