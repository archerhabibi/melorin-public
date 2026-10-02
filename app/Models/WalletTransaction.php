<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'operation_id', 'type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    /**
     * فقط برای نمایش (بند ۵۶ زیرسند وب: ترجمه‌ی نمایشی، نه منطق
     * تجاری) — دقیقاً هم‌الگو با Order::statusLabels() موجود.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'charge' => 'شارژ کیف‌پول',
            'purchase' => 'خرید',
            'refund' => 'بازگشت وجه',
            'commission' => 'کمیسیون',
            'referral_bonus' => 'پاداش معرفی',
            'admin_adjust' => 'اصلاح توسط مدیر',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
