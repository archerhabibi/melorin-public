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
        'main_price', 'reseller_price', 'customers_price', 'payment_id', 'status',
        'provision_attempts', 'failure_reason',
        'renews_account_id', 'next_provision_retry_at',
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
        'main_price' => 'decimal:2',
        'reseller_price' => 'decimal:2',
        'customers_price' => 'decimal:2',
        'next_provision_retry_at' => 'datetime',
    ];

    /**
     * برچسب فارسی وضعیت‌ها برای پنل ادمین (badge، فیلتر، صفحه‌ی مشاهده).
     * تا امروز provisioning و provision_failed در پنل با رنگ/برچسب خام
     * نشان داده می‌شدند و در فیلتر اصلاً وجود نداشتند — یعنی ادمین راهی
     * برای پیداکردن سفارش‌های «پول گرفته شده، سرویس تحویل نشده» نداشت.
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'در انتظار',
            self::STATUS_PAID => 'پرداخت‌شده',
            self::STATUS_PROVISIONING => 'در حال ساخت',
            self::STATUS_ACCOUNT_CREATED => 'تحویل‌شده',
            self::STATUS_PROVISION_FAILED => 'ساخت ناموفق — نیازمند رسیدگی',
            self::STATUS_FAILED => 'ناموفق',
            self::STATUS_REFUNDED => 'بازگشت‌شده',
        ];
    }

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

    /** اکانتی که این سفارشِ تمدید، تمدیدش می‌کند (null برای سفارش خرید) */
    public function renewedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'renews_account_id');
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

    /* ------------------------------------------------------------------
     | سه قیمت سند معماری، روی همین سفارش (بند ۱۵ و ۲۱).
     |
     | از مرحله ۵ (Pricing Migration) این سه، ستون فیزیکی مستقل خودشان
     | را دارند — نه یک عدد مشترک زیر سه نام. مقدار null یعنی «این
     | قیمت در Context این سفارش اصلاً نقشی ندارد» (بند ۸ و ۱۱ سند)،
     | و همین null بودن با خودِ ستون تضمین می‌شود، نه با یک Accessor.
     ------------------------------------------------------------------ */

    /**
     * سفارش تمدید یک اکانت موجود است، نه خرید اکانت جدید. این تمایز برای
     * Provisioning حیاتی است: retry روی سفارش تمدید باید همان اکانت را
     * تمدید کند، نه اکانت تازه بسازد.
     */
    public function isRenewal(): bool
    {
        return $this->renews_account_id !== null;
    }

    public function isResellerContext(): bool
    {
        return $this->reseller_id !== null;
    }

    /**
     * حاشیه‌ی فروش نماینده (بند ۱۴): Customers_price − reseller_price.
     *
     * صرفاً یک عدد گزارشی است. طبق بند ۱۴ و Rule 6، محاسبه‌ی حاشیه هرگز
     * نباید باعث شود Customers_price به‌جای reseller_price از کیف‌پول
     * نماینده کسر شود.
     */
    public function resellerProfit(): float
    {
        if (! $this->isResellerContext()) {
            return 0.0;
        }

        return (float) $this->customers_price - (float) $this->reseller_price;
    }
}
