<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'product_id', 'reseller_id', 'sales_channel',
        'base_price', 'sold_price', 'payment_id', 'status',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'sold_price' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    /**
     * محدودسازی به سفارش‌های یک نماینده‌ی مشخص (بند ۵ سند معماری
     * Reseller: «هیچ Query مربوط به Reseller نباید بدون Scope استفاده
     * شود»). این متد نقطه‌ی واحد اعمال آن قانون برای Order است.
     */
    public function scopeOfReseller($query, int $resellerId)
    {
        return $query->where('reseller_id', $resellerId);
    }

    /** سود نماینده از این سفارش (بند ۲۱ سند نیازمندی) */
    public function resellerProfit(): float
    {
        return (float) $this->sold_price - (float) $this->base_price;
    }
}
