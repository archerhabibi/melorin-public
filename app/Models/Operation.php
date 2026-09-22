<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * یک عملیات حساس و تکرارناپذیر — بند ۲۶ و ۴۸ بلوپرینت.
 *
 * انواع فعلی: purchase, provision, renewal, payment_confirmation,
 * refund, webhook.
 */
class Operation extends Model
{
    use HasFactory;

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_PROVISION = 'provision';

    public const TYPE_RENEWAL = 'renewal';

    public const TYPE_PAYMENT_CONFIRMATION = 'payment_confirmation';

    public const TYPE_REFUND = 'refund';

    public const TYPE_WEBHOOK = 'webhook';

    protected $fillable = [
        'idempotency_key', 'type', 'status', 'reference_type', 'reference_id',
        'payload', 'result_payload', 'attempts', 'last_error',
        'available_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result_payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markCompleted(array $result = [], ?Model $reference = null): void
    {
        $this->update([
            'status' => 'completed',
            'result_payload' => $result,
            'reference_type' => $reference?->getMorphClass() ?? $this->reference_type,
            'reference_id' => $reference?->getKey() ?? $this->reference_id,
            'processed_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * زمان‌بندی تلاش مجدد روی خودِ Order نگه‌داری می‌شود
     * (orders.next_provision_retry_at، ر.ک. ProvisioningFailureHandler)؛ Operation فقط
     * وضعیت «یک‌بار اجرا» را ثبت می‌کند.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'last_error' => mb_substr($error, 0, 2000),
            'processed_at' => now(),
        ]);
    }
}
