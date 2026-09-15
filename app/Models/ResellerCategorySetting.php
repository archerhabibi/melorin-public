<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تصمیم خودِ نماینده درباره‌ی اینکه یک سبد فروش در ربات او نمایش داده
 * شود یا نه. ر.ک. مهاجرت create_reseller_category_settings_table برای
 * تفاوت این کلید با Category::available_to_resellers (که تصمیم Core و
 * بالادستِ این است).
 */
class ResellerCategorySetting extends Model
{
    protected $fillable = ['reseller_id', 'category_id', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
