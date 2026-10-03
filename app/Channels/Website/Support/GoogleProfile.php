<?php

namespace App\Channels\Website\Support;

/** Claimهای اعتبارسنجی‌شده‌ی id_token گوگل (فقط آنچه لازم داریم). */
final class GoogleProfile
{
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {}
}
