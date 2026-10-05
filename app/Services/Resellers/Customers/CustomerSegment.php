<?php

namespace App\Services\Resellers\Customers;

/**
 * بخش‌بندی مشتریان نماینده (B5.2) — هر بخش یک پرسش مدیریتی است («به چه کسی باید پیام بدهم؟»).
 * تعریف هر بخش فقط در ResellerCustomerDirectory::applySegment() است تا شمارنده‌ی خلاصه و فیلتر جدول یکی باشند.
 */
enum CustomerSegment: string
{
    case ActiveService = 'active_service';
    case Expiring = 'expiring';
    case NeedsRenewal = 'needs_renewal';
    case NeverBought = 'never_bought';
    case HasBalance = 'has_balance';

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /** مقدار نامعتبر (مثلاً از URL) ⇒ null، نه خطا */
    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::ActiveService => 'سرویس فعال دارند',
            self::Expiring => 'سرویس رو‌به‌انقضا',
            self::NeedsRenewal => 'نیازمند تمدید (همه‌ی سرویس‌ها تمام شده)',
            self::NeverBought => 'هنوز خرید نکرده‌اند',
            self::HasBalance => 'موجودی کیف‌پول دارند',
        };
    }
}
