<?php

namespace App\Services\Core\Identity;

use App\Models\CustomerAccount;
use App\Models\User;

/**
 * نتیجه‌ی Resolve هویت بیرونی. `user` فقط وقتی پر است که ورود مجاز باشد.
 * `customerAccount` عضویت User در StoreContext همان ورود است (G21)؛ اگر Context
 * داده نشده یا ساخت/خواندنش ناموفق بوده، null می‌ماند و ورود را نمی‌شکند.
 */
final class ExternalIdentityResult
{
    public const LOGIN = 'login';
    public const REGISTERED = 'registered';
    public const LINKED = 'linked';
    public const REJECTED = 'rejected';

    public const REASON_EMAIL_NOT_VERIFIED = 'provider_email_not_verified';
    public const REASON_LOCAL_EMAIL_UNVERIFIED = 'local_email_unverified';
    public const REASON_LINK_REQUIRES_LOGIN = 'link_requires_login';
    public const REASON_USER_NOT_ACTIVE = 'user_not_active';
    public const REASON_USER_UNAVAILABLE = 'user_unavailable';
    public const REASON_RACE = 'race_lost';

    private function __construct(
        public readonly string $outcome,
        public readonly ?User $user = null,
        public readonly ?string $reason = null,
        public readonly ?CustomerAccount $customerAccount = null,
        public readonly bool $customerAccountCreated = false,
    ) {}

    public static function ok(string $outcome, User $user): self
    {
        return new self($outcome, $user);
    }

    public function withCustomerAccount(CustomerAccount $customerAccount, bool $created): self
    {
        return new self($this->outcome, $this->user, $this->reason, $customerAccount, $created);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, $reason);
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }
}
