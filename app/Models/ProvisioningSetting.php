<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تنظیم سراسری «سیاست شکست Provisioning» — نگاه کنید به کامنت کامل در
 * migration ایجادکننده‌ی این جدول.
 */
class ProvisioningSetting extends Model
{
    public const POLICY_RETRY = 'retry';

    public const POLICY_REFUND = 'refund';

    protected $fillable = ['failure_policy'];

    /** تنظیمات فعال سیستم (تک‌رکوردی) */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['failure_policy' => self::POLICY_RETRY]);
    }

    public static function refundsAutomatically(): bool
    {
        return static::current()->failure_policy === self::POLICY_REFUND;
    }
}
