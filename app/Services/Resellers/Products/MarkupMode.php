<?php

namespace App\Services\Resellers\Products;

/** شیوه‌ی قیمت‌گذاری گروهی (B5.3): همیشه نسبت به قیمت تأمین (reseller_price) محاسبه می‌شود. */
enum MarkupMode: string
{
    /** سود = درصدی از reseller_price (گردکردن به نزدیک‌ترین واحد) */
    case Percent = 'percent';

    /** سود = مبلغ ثابت */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'درصد روی قیمت تأمین',
            self::Fixed => 'مبلغ ثابت روی قیمت تأمین',
        };
    }

    /** حداکثر درصد مجاز (جلوی خطای تایپی مثل ۲۰۰۰٪ را می‌گیرد) */
    public const MAX_PERCENT = 1000;
}
