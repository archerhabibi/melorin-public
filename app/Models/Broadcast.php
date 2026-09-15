<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک کمپین پیام همگانی. reseller_id تهی یعنی پیام همگانیِ ادمین اصلی
 * (با ربات اصلی)؛ در غیر این صورت متعلق به همان نماینده است و فقط
 * مشتریان خودش را هدف می‌گیرد.
 */
class Broadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'reseller_id', 'message', 'status', 'total_recipients',
        'sent_count', 'failed_count', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /** درصد پیشرفت — برای نمایش در پنل */
    public function progressPercent(): int
    {
        if ($this->total_recipients === 0) {
            return 100;
        }

        return (int) round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100);
    }
}
