<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قیمت فروش یک محصول توسط یک نماینده به مشتریان خودش — یعنی همان
 * `Customers_price` سند معماری (بند ۶).
 *
 * ستون فیزیکی هنوز `custom_price` نام دارد (تغییرش یعنی migration روی
 * نصب‌های فعال)، ولی در کد فقط با نام قانونی `customers_price` خوانده و
 * نوشته می‌شود تا واژگان پروژه با سند یکی بماند.
 */
class ResellerProductPrice extends Model
{
    protected $fillable = ['reseller_id', 'product_id', 'custom_price', 'customers_price', 'is_enabled'];

    protected $casts = [
        'custom_price' => 'decimal:2',
        'is_enabled' => 'boolean',
    ];

    /** `Customers_price` — نام قانونی همان ستون custom_price */
    protected function customersPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->custom_price === null ? null : (float) $this->custom_price,
            set: fn ($value) => ['custom_price' => $value],
        );
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
