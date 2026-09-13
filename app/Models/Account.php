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
        'user_id', 'order_id', 'product_id', 'server_panel_id', 'protocol_id',
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
