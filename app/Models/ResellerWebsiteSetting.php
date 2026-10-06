<?php

namespace App\Models;

use App\Services\Resellers\Branding\StoreBrandResolver;
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
        'allow_indexing', 'meta_description',
    ];

    // ستون‌های custom_domain_* (B6.1) عمداً fillable نیستند: فقط ResellerDomainService با forceFill می‌نویسد.
    protected $casts = [
        'allow_indexing' => 'boolean',
        'custom_domain_verified_at' => 'datetime',
        'custom_domain_checked_at' => 'datetime',
        'custom_domain_claimed_at' => 'datetime',
    ];

    protected $hidden = ['custom_domain_token'];

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
     * برندینگِ نهاییِ قابل‌نمایش (بند ۴۶) در قالب آرایه‌ی قدیمی.
     *
     * از B5.7 منطق در `StoreBrandResolver` است (یک منبع برای Layout، پنل نماینده، صفحه‌ی نتیجه‌ی پرداخت
     * و صفحه‌ی تنظیمات)؛ این متد فقط برای سازگاری با مصرف‌کننده‌های قدیمی باقی مانده است.
     *
     * @return array{name: string, logo_url: ?string, color: string, phone: ?string, email: ?string, about: ?string}
     */
    public static function brandingFor(?Reseller $reseller): array
    {
        return app(StoreBrandResolver::class)->forReseller($reseller)->toLegacyArray();
    }
}
