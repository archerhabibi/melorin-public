<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'price', 'reseller_price', 'traffic_gb', 'duration_days',
        'protocol_id', 'status', 'sale_limit', 'allowed_panel_ids',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'reseller_price' => 'decimal:2',
        'traffic_gb' => 'decimal:2',
        'allowed_panel_ids' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(Protocol::class);
    }

    /**
     * قیمتی که پلتفرم از اعتبار نماینده کسر می‌کند (Double-Debit
     * base_price) — نه قیمت فروش نماینده (که sellingPriceForReseller
     * برمی‌گرداند و کاملاً در اختیار خودِ نماینده است). اگر ادمین برای
     * این محصول reseller_price ست نکرده باشد، به قیمت خرده‌فروشی/مشتری
     * سقوط می‌کند — یعنی محصولات قدیمی بدون اقدام صریح رفتارشان عوض
     * نمی‌شود.
     */
    public function resellerBasePrice(): float
    {
        return (float) ($this->reseller_price ?? $this->price);
    }

    public function resellerPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }

    /** قیمت نهایی برای یک نماینده‌ی مشخص؛ اگر تعریف نشده بود، قیمت پایه برگردانده می‌شود */
    public function priceForReseller(?Reseller $reseller): float
    {
        if (! $reseller) {
            return (float) $this->price;
        }

        $custom = $this->resellerPrices()->where('reseller_id', $reseller->id)->first();

        return $custom ? (float) $custom->custom_price : (float) $this->price;
    }

    /**
     * قیمت فروشِ واقعاً قابل‌استفاده برای این نماینده، یا null اگر این
     * محصول برای این نماینده اصلاً قابل‌فروش نیست. برخلاف
     * priceForReseller() (که برای سازگاری با کد قدیمی نگه داشته شده و
     * در نبود تنظیمات به قیمت پایه سقوط می‌کند)، این متد طبق بند ۵ سند
     * نیازمندی Reseller Platform یک مدل opt-in واقعی است: محصول فقط
     * وقتی قابل‌فروش است که هم در سیستم اصلی فعال باشد و هم خودِ نماینده
     * صراحتاً آن را فعال و قیمت‌گذاری کرده باشد. این دقیقاً همان قانونی
     * است که در سند تصریح شده: «اگر Admin اصلی Product را غیرفعال کند،
     * فعال‌سازی محلی نماینده نباید آن را قابل‌فروش کند.»
     */
    public function sellingPriceForReseller(Reseller $reseller): ?float
    {
        if ($this->status !== 'active') {
            return null;
        }

        $setting = $this->resellerPrices()
            ->where('reseller_id', $reseller->id)
            ->where('is_enabled', true)
            ->first();

        return $setting ? (float) $setting->custom_price : null;
    }
}
