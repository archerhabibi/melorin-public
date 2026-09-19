<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تنظیم سراسری «سیاست شکست Provisioning» (بند ۳۵ تا ۳۷ سند معماری).
 *
 * سه سیاست:
 *
 *   retry             — تلاش مجدد خودکار (حداکثر ۳ بار، فاصله‌ی فزاینده)؛
 *                       هرگز بازگشت وجه خودکار. اگر همه‌ی تلاش‌ها شکست
 *                       بخورد، سفارش برای رسیدگی ادمین می‌ماند.
 *   refund            — بدون تلاش مجدد؛ اولین شکست = بازگشت فوری و
 *                       دوطرفه‌ی وجه (مشتری + نماینده).
 *   retry_then_refund — «هر دو»: اول تلاش مجدد خودکار، و اگر همه‌ی تلاش‌ها
 *                       شکست خورد، بازگشت خودکار وجه.
 *
 * سیاست در لحظه‌ی هر شکست خوانده می‌شود (نه در لحظه‌ی خرید)، و برای
 * خرید و تمدید یکسان اعمال می‌شود.
 */
class ProvisioningSetting extends Model
{
    public const POLICY_RETRY = 'retry';

    public const POLICY_REFUND = 'refund';

    public const POLICY_RETRY_THEN_REFUND = 'retry_then_refund';

    protected $fillable = ['failure_policy'];

    /** @return list<string> */
    public static function policies(): array
    {
        return [self::POLICY_RETRY, self::POLICY_REFUND, self::POLICY_RETRY_THEN_REFUND];
    }

    /** تنظیمات فعال سیستم (تک‌رکوردی) */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['failure_policy' => self::POLICY_RETRY]);
    }

    /** سیاست فعال؛ مقدار نامعتبر در دیتابیس به retry (امن‌ترین) برمی‌گردد. */
    public static function activePolicy(): string
    {
        $value = static::current()->failure_policy;

        return in_array($value, self::policies(), true) ? $value : self::POLICY_RETRY;
    }

    public static function retriesAutomatically(): bool
    {
        return in_array(static::activePolicy(), [self::POLICY_RETRY, self::POLICY_RETRY_THEN_REFUND], true);
    }
}
