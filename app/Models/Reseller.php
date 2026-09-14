<?php

namespace App\Models;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

class Reseller extends Model implements HasName
{
    use HasFactory;

    protected $fillable = ['user_id', 'bot_token', 'webhook_slug', 'slug', 'status', 'min_sale_price_rule'];

    protected $casts = [
        'bot_token' => 'encrypted',
        'min_sale_price_rule' => 'array',
    ];

    public function wallet(): MorphOne
    {
        return $this->morphOne(Wallet::class, 'owner');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(User::class, 'reseller_id');
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(ResellerAdmin::class);
    }

    public function botSetting(): HasOne
    {
        return $this->hasOne(ResellerBotSetting::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** آدرس کامل پنل وب این نماینده — طبق درخواست صریح، بر اساس نام لاتین خودش (بند تنانسی Filament) */
    public function panelUrl(): string
    {
        return rtrim(config('app.url'), '/').'/'.$this->slug;
    }

    /**
     * برای منوی Tenant Switcher و عنوان صفحات پنل نماینده. بدون این،
     * Filament به دنبال ستونی به‌نام دقیقاً «name» روی خودِ Reseller
     * می‌گشت (که چنین ستونی هرگز وجود نداشته) و باعث همان TypeError
     * واقعی «Return value must be of type string, null returned»
     * می‌شد (این‌بار برای Tenant، نه User).
     */
    public function getFilamentName(): string
    {
        return $this->user?->full_name ?: ('فروشگاه '.$this->slug);
    }

    protected static function booted(): void
    {
        static::creating(function (self $reseller) {
            if (! $reseller->webhook_slug) {
                $reseller->webhook_slug = Str::random(40);
            }

            if (! $reseller->slug) {
                $reseller->slug = Str::slug(Str::random(8));
            }
        });
    }
}
