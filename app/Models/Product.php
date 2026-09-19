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
        'category_id', 'name', 'main_price', 'reseller_price', 'traffic_gb', 'duration_days',
        'protocol_id', 'status', 'sale_limit', 'allowed_panel_ids',
    ];

    protected $casts = [
        'main_price' => 'decimal:2',
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

    /* ------------------------------------------------------------------
     | سه قیمت سیستم (سند معماری Reseller، بندهای ۳ تا ۷).
     |
     |     main_price      → فروش مستقیم Main به مشتری Main
     |     reseller_price  → قیمت تأمین محصول از Main برای نماینده
     |     customers_price → قیمت فروش نماینده به مشتریان خودش
     |
     | هیچ نام دیگری برای قیمت در کد استفاده نمی‌شود. مقادیر عددی ممکن
     | است برابر شوند (بند ۲۰، Rule 5) ولی معنایشان هرگز یکی نیست، پس
     | هرکدام متد مستقل خودشان را دارند.
     |
     | نگاشت به ستون‌های دیتابیس (مرحله ۵ سند: rename واقعی، نه Alias):
     |     main_price      = products.main_price
     |     reseller_price  = products.reseller_price ?? products.main_price
     |     customers_price = reseller_product_prices.customers_price
     ------------------------------------------------------------------ */

    /**
     * `main_price` — قیمتی که مشتریِ مستقیمِ فروشگاه اصلی می‌پردازد
     * (بند ۴). فقط و فقط در فروش مستقیم Main استفاده می‌شود.
     */
    public function mainPrice(): float
    {
        return (float) $this->main_price;
    }

    /**
     * `reseller_price` — قیمتی که Main بابت تأمین این محصول از کیف‌پول
     * نماینده در Main کسر می‌کند (بند ۵). این قیمتِ فروشِ نماینده به
     * مشتری نیست (بند ۲۰، Rule 8).
     *
     * اگر ادمین برای محصول reseller_price تعیین نکرده باشد، به
     * main_price سقوط می‌کند تا محصولات قدیمی بدون اقدام صریح رفتارشان
     * عوض نشود.
     */
    public function resellerPrice(): float
    {
        return (float) ($this->reseller_price ?? $this->main_price);
    }

    public function resellerPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }

    /**
     * `customers_price` — قیمتی که مشتریِ یک نماینده در Context همان
     * نماینده می‌پردازد (بند ۶). این عدد کاملاً در اختیار خودِ نماینده
     * است و برای هر نماینده می‌تواند متفاوت باشد.
     *
     * null یعنی «این محصول برای این نماینده قابل‌فروش نیست» — مدل
     * opt-in واقعی طبق بند ۵ سند نیازمندی: محصول فقط وقتی قابل‌فروش است
     * که هم در سیستم اصلی فعال باشد و هم خودِ نماینده صراحتاً آن را فعال
     * و قیمت‌گذاری کرده باشد. یعنی غیرفعال‌کردن محصول توسط ادمین اصلی
     * همیشه بالادستِ فعال‌سازی محلی نماینده است.
     */
    public function customersPrice(Reseller $reseller): ?float
    {
        if ($this->status !== 'active') {
            return null;
        }

        $setting = $this->resellerPrices()
            ->where('reseller_id', $reseller->id)
            ->where('is_enabled', true)
            ->first();

        return $setting ? (float) $setting->customers_price : null;
    }
}
