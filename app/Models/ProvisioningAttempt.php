<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک تلاشِ واحدِ Provisioning برای یک سفارش — بند ۶۹ سند v2.1، فاز A3.
 *
 * برخلاف orders.provision_attempts (که فقط یک شمارنده است)، هر ردیف
 * این جدول دقیقاً یک تلاش را نمایندگی می‌کند و به Operation (در صورت
 * وجود idempotency key) متصل است.
 */
class ProvisioningAttempt extends Model
{
    use HasFactory;

    public const STATUS_STARTED = 'started';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'operation_id', 'attempt_number', 'status',
        'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
