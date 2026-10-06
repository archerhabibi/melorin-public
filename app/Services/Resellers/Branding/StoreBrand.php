<?php

namespace App\Services\Resellers\Branding;

use App\Support\Branding\BrandColor;

/**
 * برندینگِ نهاییِ قابل‌نمایشِ یک فروشگاه (Main یا نماینده) — تغییرناپذیر.
 *
 * تنها منبعی که Layout وب، پنل نماینده، صفحه‌ی نتیجه‌ی پرداخت و صفحه‌ی تنظیمات می‌خوانند
 * (B5.7)؛ هیچ‌کدام نباید پیش‌فرض خودشان را دوباره اختراع کنند. مقدارها همه «قابل‌چاپ‌اند»:
 * رنگ فقط hex شش‌رقمی معتبر است و آدرس لوگو از دیسک عمومی می‌آید.
 */
final class StoreBrand
{
    public const MAIN_NAME = 'Melorin';

    public function __construct(
        public readonly string $name,
        public readonly ?string $logoUrl,
        public readonly string $color,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $about,
        public readonly bool $allowIndexing,
        public readonly ?string $metaDescription,
        /** true = فروشگاه Main (برند خودِ پلتفرم)؛ false = فروشگاه نماینده */
        public readonly bool $isMain,
        /** true = نماینده خودش نام نمایشی داده؛ false = `name` همان برچسب پیش‌فرض Context است */
        public readonly bool $nameIsCustom = false,
    ) {}

    /** رنگ متن روی پس‌زمینه‌ی Brand (WCAG). */
    public function onColor(): string
    {
        return BrandColor::onColor($this->color);
    }

    /** رنگ اصلی پنل Filament (متن سفید روی آن خوانا است). */
    public function panelColor(): string
    {
        return BrandColor::readableWithWhite($this->color);
    }

    /**
     * مقدار تگ robots. Main همیشه ایندکس‌پذیر است (تگی چاپ نمی‌شود = null)؛ نماینده فقط اگر خودش
     * خواسته باشد. صفحه‌ی فیلترشده‌ی کاتالوگ همچنان `noindex,follow` خودش را دارد (B4.1).
     */
    public function robots(): ?string
    {
        if ($this->isMain) {
            return null;
        }

        return $this->allowIndexing ? null : 'noindex, nofollow';
    }

    /** توضیح متا فقط برای فروشگاهی که ایندکس را فعال کرده (وگرنه بی‌اثر و فقط نویز است). */
    public function description(): ?string
    {
        if ($this->isMain || ! $this->allowIndexing) {
            return null;
        }

        return $this->metaDescription ?: null;
    }

    public function hasContact(): bool
    {
        return $this->phone !== null || $this->email !== null;
    }

    /**
     * قالب قدیمیِ آرایه‌ای (`ResellerWebsiteSetting::brandingFor`) — برای سازگاری با مصرف‌کننده‌های موجود.
     *
     * @return array{name: string, logo_url: ?string, color: string, phone: ?string, email: ?string, about: ?string}
     */
    public function toLegacyArray(): array
    {
        return [
            'name' => $this->name,
            'logo_url' => $this->logoUrl,
            'color' => $this->color,
            'phone' => $this->phone,
            'email' => $this->email,
            'about' => $this->about,
        ];
    }
}
