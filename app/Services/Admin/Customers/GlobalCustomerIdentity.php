<?php

namespace App\Services\Admin\Customers;

/**
 * فیلتر «روش‌های هویتی متصل» (B7.2). تعریف‌ها فقط در GlobalCustomerDirectory::applyIdentity() است.
 * `google_and_telegram` همان «هویت یکپارچه» (Website ↔ Telegram) است.
 */
enum GlobalCustomerIdentity: string
{
    case Google = 'google';
    case Telegram = 'telegram';
    case GoogleAndTelegram = 'google_and_telegram';
    case EmailVerified = 'email_verified';
    case EmailUnverified = 'email_unverified';

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Google => 'متصل به Google',
            self::Telegram => 'متصل به تلگرام',
            self::GoogleAndTelegram => 'هر دو (Google + تلگرام)',
            self::EmailVerified => 'ایمیل تأییدشده',
            self::EmailUnverified => 'ایمیل تأییدنشده',
        };
    }
}
