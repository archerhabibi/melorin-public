<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Guest Checkout Token. مرجع کامل تصمیم در
 * docs/history/PHASE-W3-PART1-GUEST-CHECKOUT-TOKEN.md.
 */
class GuestCheckout extends Model
{
    protected $fillable = [
        'token', 'product_id', 'reseller_id',
        'guest_name', 'guest_phone', 'guest_email',
        'status', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** دقیقه‌های باقی‌مانده تا انقضا (برای Pending Page)؛ هرگز منفی نیست. */
    public function minutesLeft(): int
    {
        return max(0, (int) ceil(now()->diffInMinutes($this->expires_at, false)));
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }
}
