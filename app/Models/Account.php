<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'customer_account_id', 'order_id', 'product_id', 'server_panel_id', 'protocol_id',
        'panel_username', 'panel_client_uuid',
        'subscription_id', 'subscription_url',
        'config_data', 'starts_at', 'expires_at', 'traffic_gb',
        'traffic_used_gb', 'status', 'is_test',
    ];

    protected $casts = [
        'config_data' => 'encrypted',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'traffic_gb' => 'decimal:2',
        'traffic_used_gb' => 'decimal:2',
        'is_test' => 'boolean',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serverPanel(): BelongsTo
    {
        return $this->belongsTo(ServerPanel::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    /**
     * سرویسِ «فعالِ این لحظه»: status=active و هنوز منقضی نشده (expires_at خالی = بدون انقضا).
     * مرجع واحد برای شمارش/فهرست داشبورد؛ شرط‌ها در Controller/View تکرار نمی‌شوند.
     */
    public function scopeActiveNow($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** سرویسِ منقضی: status=expired، یا هنوز active ولی تاریخ انقضا گذشته (sync با پنل عقب است). */
    public function scopeLapsed($query)
    {
        return $query->where(fn ($q) => $q->where('status', 'expired')
            ->orWhere(fn ($q2) => $q2->where('status', 'active')->where('expires_at', '<=', now())));
    }

    /**
     * روزهای باقی‌مانده (گردشده به بالا؛ هیچ‌وقت منفی نیست). null = بدون تاریخ انقضا.
     * محاسبه با timestamp است تا به نسخه‌ی Carbon (علامتِ diffIn*) وابسته نباشد.
     */
    public function remainingDays(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) ceil(($this->expires_at->getTimestamp() - now()->getTimestamp()) / 86400));
    }

    /**
     * درصد حجم مصرف‌شده (۰ تا ۱۰۰). null = نامحدود. اگر مصرف از کل بیشتر ثبت شده
     * (تأخیر sync) ۱۰۰ برمی‌گردد؛ ۹۹٫۹٪ به ۱۰۰ گرد نمی‌شود تا «تمام‌شده» دقیق بماند.
     */
    public function trafficUsagePercent(): ?int
    {
        if ($this->traffic_gb === null || (float) $this->traffic_gb <= 0) {
            return null;
        }

        $used = (float) ($this->traffic_used_gb ?? 0);
        $total = (float) $this->traffic_gb;

        return $used >= $total ? 100 : max(0, (int) floor($used / $total * 100));
    }

    /**
     * محدودسازی اکانت‌ها به آن‌هایی که سفارش‌شان متعلق به یک نماینده‌ی
     * مشخص است — طبق «اصل طلایی مشتری نماینده» (سند نیازمندی، بند ۲):
     * حتی وقتی مشتری خودش عضو یک Reseller است، در بستر یک ربات/پنل
     * نمایندگی دیگر نباید اکانت‌هایش را ببیند؛ Scope همیشه از طریق
     * Order.reseller_id تعیین می‌شود، نه User.reseller_id.
     */
    public function scopeOfReseller($query, int $resellerId)
    {
        return $query->whereHas('order', fn ($q) => $q->where('reseller_id', $resellerId));
    }

    /**
     * حجم باقی‌مانده به گیگابایت. null یعنی نامحدود (traffic_gb خالی است).
     * هیچ‌وقت منفی برنمی‌گرداند — اگر مصرف از کل هم بیشتر ثبت شده باشد
     * (مثلاً به‌خاطر تأخیر sync با پنل)، صفر نشان داده می‌شود، نه عدد منفی.
     */
    public function remainingTrafficGb(): ?float
    {
        if ($this->traffic_gb === null) {
            return null;
        }

        return max(0.0, (float) $this->traffic_gb - (float) ($this->traffic_used_gb ?? 0));
    }
}
