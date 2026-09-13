<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerBotSetting extends Model
{
    protected $fillable = ['reseller_id', 'bot_enabled', 'support_id', 'rules', 'connection_guide'];

    protected $casts = ['bot_enabled' => 'boolean'];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** تنظیمات ربات یک نماینده‌ی مشخص (تک‌رکوردی به‌ازای هر Reseller) */
    public static function forReseller(Reseller $reseller): self
    {
        return static::query()->firstOrCreate(['reseller_id' => $reseller->id]);
    }
}
