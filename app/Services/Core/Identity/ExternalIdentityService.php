<?php

namespace App\Services\Core\Identity;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Core\AuditService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolve هویت بیرونی (Google) به User — منطق Identity در Core (مشترک بین کانال‌ها)،
 * نه در Controller Website. قواعد: GOOGLE-SIGNIN-CONTRACT.md §G13–G17.
 *
 * ترتیب Resolve (اولین تطابق برنده است):
 *  1) (provider, sub) از قبل وصل است → ورود (اگر User فعال باشد).
 *  2) Email سمت Provider تأییدشده نیست → رد.
 *  3) User محلی با همان Email:
 *       - حذف‌شده (Soft Delete) / غیرفعال → رد؛
 *       - Email محلی تأییدنشده → رد (ضد Pre-hijack: مهاجم می‌توانسته با Email قربانی
 *         ثبت‌نام کرده باشد)؛
 *       - auto_link خاموش → رد (باید با رمز وارد شود)؛
 *       - وگرنه → Link + ورود.
 *  4) User وجود ندارد → ساخت User (Email تأییدشده، بدون رمز) + هویت + ورود.
 *
 * پس از هر نتیجه‌ی موفق (ورود / ثبت‌نام / Link) و فقط اگر `$store` داده شده باشد،
 * CustomerAccount همان User در همان فروشگاه Resolve می‌شود: موجود ⇒ همان، نبود ⇒ ساخته
 * می‌شود (G21؛ تصمیم صاحب پروژه — استثنای R7 فقط برای Google). ساخت همیشه از مسیر
 * `Store\IdentityService::resolveCustomerAccount` است (Idempotent، ضد Race).
 *
 * هرگز: Merge چند User، ساخت Wallet/Order، یا تطبیق بر اساس چیزی جز `sub` و Email تأییدشده.
 */
class ExternalIdentityService
{
    public function __construct(
        protected AuditService $audit,
        protected LoginMembershipService $membership,
    ) {}

    public function resolve(
        string $provider,
        string $subject,
        string $email,
        bool $emailVerified,
        ?string $name = null,
        ?int $referrerId = null,
        bool $autoLink = true,
        ?StoreContext $store = null,
    ): ExternalIdentityResult {
        $email = Str::lower(trim($email));

        // یک‌بار تکرار فقط برای Race روی Unique Index (دو Callback هم‌زمان).
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $result = $this->attempt($provider, $subject, $email, $emailVerified, $name, $referrerId, $autoLink);

                return $result->isRejected()
                    ? $result
                    : $this->ensureCustomerAccount($provider, $result, $store);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return $this->reject($provider, ExternalIdentityResult::REASON_RACE, $email);
    }

    /**
     * G21: ورود موفق با Provider بیرونی ⇒ CustomerAccount همان User در همان فروشگاه
     * (منطق مشترک با ورود Email+Password در `LoginMembershipService`).
     */
    protected function ensureCustomerAccount(string $provider, ExternalIdentityResult $result, ?StoreContext $store): ExternalIdentityResult
    {
        $ensured = $this->membership->ensure($result->user, $store, $provider);

        return $ensured ? $result->withCustomerAccount($ensured[0], $ensured[1]) : $result;
    }

    protected function attempt(
        string $provider,
        string $subject,
        string $email,
        bool $emailVerified,
        ?string $name,
        ?int $referrerId,
        bool $autoLink,
    ): ExternalIdentityResult {
        // 1) هویت موجود
        $identity = UserIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $subject)
            ->first();

        if ($identity) {
            $user = User::query()->find($identity->user_id); // Soft-deleted ⇒ null

            if (! $user) {
                return $this->reject($provider, ExternalIdentityResult::REASON_USER_UNAVAILABLE, $email);
            }

            if ($user->status !== 'active') {
                return $this->reject($provider, ExternalIdentityResult::REASON_USER_NOT_ACTIVE, $email, $user);
            }

            $identity->forceFill(['provider_email' => $email, 'last_login_at' => now()])->save();

            $this->audit->record("identity.{$provider}_login", $user, actor: $user);

            return ExternalIdentityResult::ok(ExternalIdentityResult::LOGIN, $user);
        }

        // 2) Email Provider باید تأییدشده باشد
        if (! $emailVerified || $email === '') {
            return $this->reject($provider, ExternalIdentityResult::REASON_EMAIL_NOT_VERIFIED, $email);
        }

        // 3) User محلی با همان Email (حتی Soft-deleted — Unique Index شامل آن‌ها هم می‌شود)
        $local = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($local) {
            if ($local->trashed()) {
                return $this->reject($provider, ExternalIdentityResult::REASON_USER_UNAVAILABLE, $email, $local);
            }

            if ($local->status !== 'active') {
                return $this->reject($provider, ExternalIdentityResult::REASON_USER_NOT_ACTIVE, $email, $local);
            }

            if (! $local->hasVerifiedEmail()) {
                return $this->reject($provider, ExternalIdentityResult::REASON_LOCAL_EMAIL_UNVERIFIED, $email, $local);
            }

            if (! $autoLink) {
                return $this->reject($provider, ExternalIdentityResult::REASON_LINK_REQUIRES_LOGIN, $email, $local);
            }

            // هر User حداکثر یک هویت از هر Provider؛ اگر Google دیگری قبلاً وصل شده، Merge نمی‌کنیم.
            if ($local->identities()->where('provider', $provider)->exists()) {
                return $this->reject($provider, ExternalIdentityResult::REASON_LINK_REQUIRES_LOGIN, $email, $local);
            }

            DB::transaction(function () use ($local, $provider, $subject, $email) {
                UserIdentity::create([
                    'user_id' => $local->id,
                    'provider' => $provider,
                    'provider_user_id' => $subject,
                    'provider_email' => $email,
                    'last_login_at' => now(),
                ]);
            });

            $this->audit->record(
                "identity.{$provider}_linked",
                $local,
                after: ['method' => 'verified_email'],
                actor: $local,
            );

            return ExternalIdentityResult::ok(ExternalIdentityResult::LINKED, $local);
        }

        // 4) User جدید
        $user = DB::transaction(function () use ($provider, $subject, $email, $name, $referrerId) {
            $user = new User;
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => now(), // Google Email را تأیید کرده (G11 برقرار است). password: عمداً NULL (ستون nullable).
                'full_name' => $name !== null && trim($name) !== '' ? trim($name) : Str::before($email, '@'),
                'status' => 'active',
                'joined_from' => 'website',
                'referrer_id' => $referrerId && User::query()->whereKey($referrerId)->exists() ? $referrerId : null,
            ])->save();

            UserIdentity::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $subject,
                'provider_email' => $email,
                'last_login_at' => now(),
            ]);

            return $user;
        });

        $this->audit->record("identity.{$provider}_registered", $user, actor: $user);

        return ExternalIdentityResult::ok(ExternalIdentityResult::REGISTERED, $user);
    }

    protected function reject(string $provider, string $reason, string $email, ?User $user = null): ExternalIdentityResult
    {
        // Email در Audit نمی‌آید (PII)؛ فقط دلیل و در صورت وجود شناسه‌ی User.
        $this->audit->record(
            "identity.{$provider}_rejected",
            $user,
            after: ['reason' => $reason],
        );

        return ExternalIdentityResult::rejected($reason);
    }
}
