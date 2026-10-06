<?php

namespace App\Services\Admin\Customers;

/**
 * بخش‌بندی سراسری مشتریان برای ادمین پلتفرم (B7.2) — هر بخش یک پرسش مدیریتی است.
 * تعریف هر بخش فقط در GlobalCustomerDirectory::applySegment() است تا شمارنده‌ی خلاصه و فیلتر جدول یکی باشند.
 */
enum GlobalCustomerSegment: string
{
    case ActiveService = 'active_service';
    case Expiring = 'expiring';
    case NeedsRenewal = 'needs_renewal';
    case NeverBought = 'never_bought';
    case HasBalance = 'has_balance';
    case MultiStore = 'multi_store';
    case OpenTicket = 'open_ticket';

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
            self::HasBalance => 'موجودی کیف‌پول دارند (هر فروشگاه)',
            self::MultiStore => 'عضو چند فروشگاه',
            self::OpenTicket => 'تیکت باز دارند',
        };
    }

    /** برچسب کوتاه برای تب‌های فهرست */
    public function shortLabel(): string
    {
        return match ($this) {
            self::ActiveService => 'سرویس فعال',
            self::Expiring => 'رو‌به‌انقضا',
            self::NeedsRenewal => 'نیازمند تمدید',
            self::NeverBought => 'بدون خرید',
            self::HasBalance => 'دارای موجودی',
            self::MultiStore => 'چندفروشگاهی',
            self::OpenTicket => 'تیکت باز',
        };
    }
}
