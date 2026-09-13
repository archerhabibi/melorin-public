<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'payment_method_id', 'amount', 'purpose',
        'receipt_image', 'depositor_name', 'status', 'gateway_reference',
        'gateway_response', 'reviewed_by', 'reviewed_at',
        'wallet_owner_type', 'reseller_id', 'reviewed_by_reseller_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    /** نماینده‌ای که این پرداخت به آن مربوط است — یا چون کیف‌پول خودِ نماینده شارژ می‌شود، یا چون مشتریِ همین نماینده کیف‌پول شخصی‌اش را شارژ می‌کند */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** اگر تاییدکننده خودِ نماینده بوده (نه ادمین اصلی) */
    public function reviewerReseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reviewed_by_reseller_id');
    }

    /** کیف‌پولِ واقعاً هدفِ این پرداخت — طبق wallet_owner_type */
    public function walletOwner(): User|Reseller|null
    {
        return $this->wallet_owner_type === 'reseller' ? $this->reseller : $this->user;
    }
}
