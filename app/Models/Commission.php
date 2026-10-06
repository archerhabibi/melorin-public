<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    /** درصدی روی هر خرید زیرمجموعه (Master §11) */
    public const TYPE_ONGOING = 'ongoing_commission';

    /** پاداش ثابت اولین خرید زیرمجموعه؛ مفهومی مستقل از کمیسیون (Master §11) */
    public const TYPE_FIRST_PURCHASE = 'first_purchase_bonus';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

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
        'base_amount' => 'integer',
        'amount' => 'integer',
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

    public function referrerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'referrer_customer_account_id');
    }

    public function referredAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'referred_customer_account_id');
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_ONGOING => 'کمیسیون خرید',
            self::TYPE_FIRST_PURCHASE => 'پاداش معرفی',
        ];
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_PAID => 'پرداخت‌شده',
            self::STATUS_PENDING => 'در انتظار',
        ];
    }
}
