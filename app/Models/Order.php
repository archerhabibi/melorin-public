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
        'user_id', 'customer_account_id', 'product_id', 'reseller_id', 'sales_channel',
        'base_price', 'core_price', 'sold_price', 'payment_id', 'status',
        'provision_attempts', 'failure_reason',
    ];

    /*
     * وضعیت‌های سفارش. دو وضعیت جدید (provisioning و provision_failed)
     * ابهام مهمی را برمی‌دارند که تا امروز وجود داشت: وضعیت failed هم
     * برای شکست مالی به کار می‌رفت و هم برای شکست ساخت اکانت، یعنی از
     * روی دیتابیس معلوم نبود پول کسر شده یا نه (بند ۲۹ بلوپرینت).
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACCOUNT_CREATED = 'account_created';

    public const STATUS_PROVISION_FAILED = 'provision_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    /** مالی انجام شده — چه اکانت ساخته شده باشد چه نه */
    public function isFinanciallySettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_PROVISIONING,
            self::STATUS_ACCOUNT_CREATED,
            self::STATUS_PROVISION_FAILED,
        ], true);
    }

    /** پول گرفته شده ولی سرویس تحویل نشده — نیازمند رسیدگی ادمین */
    public function needsAttention(): bool
    {
        return $this->status === self::STATUS_PROVISION_FAILED;
    }

    protected $casts = [
        'base_price' => 'decimal:2',
        'core_price' => 'decimal:2',
        'sold_price' => 'decimal:2',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

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
