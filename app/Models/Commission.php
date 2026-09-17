<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_user_id',
        'referrer_customer_account_id',
        'referred_customer_account_id',
        'order_id',
        'type',
        'commission_rate',
        'base_amount',
        'amount',
        'status',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
