<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قیمت فروش یک محصول توسط یک نماینده به مشتریان خودش — یعنی همان
 * `Customers_price` سند معماری (بند ۶).
 *
 * از مرحله ۵ (Pricing Migration) به بعد، ستون فیزیکی هم دقیقاً همین نام
 * را دارد — دیگر نیازی به Accessor/Attribute جداگانه برای نگاشت به یک
 * نام قدیمی نیست (طبق بند ۱۲ و ۵۵ سند: بدون Alias برای نام‌های قدیمی).
 */
class ResellerProductPrice extends Model
{
    protected $fillable = ['reseller_id', 'product_id', 'customers_price', 'is_enabled'];

    protected $casts = [
        'customers_price' => 'decimal:2',
        'is_enabled' => 'boolean',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
