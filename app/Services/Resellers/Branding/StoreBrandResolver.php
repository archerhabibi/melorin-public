<?php

namespace App\Services\Resellers\Branding;

use App\Models\Reseller;
use App\Models\ResellerWebsiteSetting;
use App\Services\Core\Store\StoreContext;
use App\Support\Branding\BrandColor;
use Illuminate\Support\Facades\Storage;

/**
 * StoreContext → StoreBrand (فقط‌خواندنی؛ هیچ رکوردی نمی‌سازد — این مسیر روی هر بازدیدِ صفحه است).
 *
 * نتیجه‌ی هر نماینده در طول یک Request نگه داشته می‌شود (Layout، Header و Footer هر کدام قبلاً جدا
 * می‌پرسیدند)؛ این Resolver Singleton است و `forget()` بعد از ذخیره‌ی برندینگ صدا زده می‌شود.
 */
class StoreBrandResolver
{
    /** @var array<string, StoreBrand> */
    private array $memo = [];

    public function forContext(StoreContext $context): StoreBrand
    {
        return $this->forReseller($context->isReseller() ? $context->reseller : null);
    }

    public function forReseller(?Reseller $reseller): StoreBrand
    {
        $key = $reseller ? 'r'.$reseller->id : 'main';

        return $this->memo[$key] ??= $reseller ? $this->build($reseller) : $this->main();
    }

    public function forget(?Reseller $reseller = null): void
    {
        if ($reseller) {
            unset($this->memo['r'.$reseller->id]);
        } else {
            $this->memo = [];
        }
    }

    private function main(): StoreBrand
    {
        return new StoreBrand(
            name: StoreBrand::MAIN_NAME,
            logoUrl: null,
            color: BrandColor::DEFAULT,
            phone: null,
            email: null,
            about: null,
            allowIndexing: true,
            metaDescription: null,
            isMain: true,
        );
    }

    private function build(Reseller $reseller): StoreBrand
    {
        $setting = ResellerWebsiteSetting::forReseller($reseller);

        // همان نامِ پیش‌فرضِ پیش از B5.7 (برچسب Context) تا فروشگاهِ بدون برندینگ دقیقاً مثل قبل دیده شود.
        $fallbackName = StoreContext::reseller($reseller)->label();

        return new StoreBrand(
            name: $setting?->display_name ?: $fallbackName,
            logoUrl: $setting?->logo_path ? Storage::disk('public')->url($setting->logo_path) : null,
            // رنگ داخل بلوک <style> چاپ می‌شود؛ فقط hex شش‌رقمی (حتی اگر از مسیری غیر از فرم آمده باشد).
            color: preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $setting?->brand_color) ? $setting->brand_color : BrandColor::DEFAULT,
            phone: $this->clean($setting?->contact_phone),
            email: $this->clean($setting?->contact_email),
            about: $this->clean($setting?->about_text),
            allowIndexing: (bool) ($setting?->allow_indexing ?? false),
            metaDescription: $this->clean($setting?->meta_description),
            isMain: false,
            nameIsCustom: (bool) $setting?->display_name,
            // لوگوی تیره بدون لوگوی اصلی بی‌معنی است (Layout هر دو را با هم نشان می‌دهد)؛ پس نادیده.
            logoDarkUrl: ($setting?->logo_path && $setting?->logo_dark_path) ? $this->url($setting->logo_dark_path) : null,
            faviconUrl: $setting?->favicon_path ? $this->url($setting->favicon_path) : null,
        );
    }

    private function url(string $path): string
    {
        return Storage::disk('public')->url($path);
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
