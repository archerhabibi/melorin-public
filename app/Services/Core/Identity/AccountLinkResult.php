<?php

namespace App\Services\Core\Identity;

/**
 * نتیجه‌ی عملیات Account Linking (B2.4). Core متن UI تولید نمی‌کند؛ فقط `reason` ماشین‌خوان
 * برمی‌گرداند و Channel پیام فارسی را می‌سازد.
 */
final class AccountLinkResult
{
    public const OK = 'ok';
    public const ALREADY = 'already';
    public const REJECTED = 'rejected';

    public const REASON_USER_NOT_ACTIVE = 'user_not_active';
    public const REASON_PROVIDER_EMAIL_NOT_VERIFIED = 'provider_email_not_verified';
    public const REASON_OWNED_BY_OTHER = 'owned_by_other';
    public const REASON_ALREADY_HAS_PROVIDER = 'already_has_provider';
    public const REASON_NOT_LINKED = 'not_linked';
    public const REASON_LAST_LOGIN_METHOD = 'last_login_method';
    public const REASON_PASSWORD_ALREADY_SET = 'password_already_set';
    public const REASON_RACE = 'race_lost';
    // B2.5
    public const REASON_NO_PASSWORD = 'no_password';
    public const REASON_WRONG_PASSWORD = 'wrong_password';
    public const REASON_PASSWORD_UNCHANGED = 'password_unchanged';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
    ) {}

    public static function ok(): self
    {
        return new self(self::OK);
    }

    public static function already(): self
    {
        return new self(self::ALREADY);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, $reason);
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }

    public function isAlready(): bool
    {
        return $this->outcome === self::ALREADY;
    }
}
