<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branding سبکِ فروشگاهِ نماینده روی
 * Website (نام نمایشی، لوگو، رنگ اصلی، اطلاعات تماس). تک‌رکوردی به‌ازای
 * هر Reseller — دقیقاً هم‌الگو با `ResellerBotSetting`.
 *
 * برخلافِ `ResellerBotSetting::forReseller()`، اینجا `firstOrCreate`
 * استفاده نمی‌شود: این مدل روی مسیر «نمایش» (هر بازدیدِ صفحه از
 * فروشگاهِ نماینده، از Layout مشترک) هم خوانده می‌شود، و نوشتنِ یک
 * ردیفِ خالی فقط برای «نگاه‌کردن» به برندینگ درست نیست. `brandingFor()`
 * وقتی رکوردی نیست، مقادیرِ پیش‌فرض را خودش برمی‌گرداند.
 */
class ResellerWebsiteSetting extends Model
{
    protected $fillable = [
        'reseller_id', 'display_name', 'logo_path', 'brand_color', 'contact_phone', 'contact_email', 'about_text',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** رکورد تنظیمات این نماینده (ممکن است هنوز چیزی ثبت نشده باشد). */
    public static function forReseller(Reseller $reseller): ?self
    {
        return static::query()->where('reseller_id', $reseller->id)->first();
    }

    /**
     * برندینگِ نهاییِ قابل‌نمایش (بند ۴۶) با fallback امن؛ Layout و
     * ManageController هر دو از همین یک منبع می‌خوانند تا قاعده‌ی
     * «هیچ‌کدام پیش‌فرض خودشان را دوباره اختراع نکنند» رعایت شود.
     *
     * @return array{name: string, logo_url: ?string, color: string, phone: ?string, email: ?string, about: ?string}
     */
    public static function brandingFor(?Reseller $reseller): array
    {
        $default = [
            'name' => 'Melorin',
            'logo_url' => null,
            'color' => '#2563eb',
            'phone' => null,
            'email' => null,
            'about' => null,
        ];

        if (! $reseller) {
            return $default;
        }

        $setting = static::forReseller($reseller);
        // همان نامِ پیش‌فرضِ قبل از این (Layout از StoreContext::label() می‌خواند)،
        // تا فروشگاهِ بدون برندینگ دقیقاً مثل قبل دیده شود.
        $fallbackName = \App\Services\Core\Store\StoreContext::reseller($reseller)->label();

        return [
            'name' => $setting?->display_name ?: $fallbackName,
            'logo_url' => $setting?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($setting->logo_path) : null,
            // رنگ داخل یک بلوکِ <style> چاپ می‌شود؛ حتی اگر مقدار از مسیری غیر از فرمِ
            // اعتبارسنجی‌شده (مثلاً یک Seeder/ویرایش مستقیم DB) آمده باشد، فقط hex شش‌رقمی
            // پذیرفته می‌شود و هر چیز دیگر به پیش‌فرض برمی‌گردد.
            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $setting?->brand_color) ? $setting->brand_color : $default['color'],
            'phone' => $setting?->contact_phone,
            'email' => $setting?->contact_email,
            'about' => $setting?->about_text,
        ];
    }
}
