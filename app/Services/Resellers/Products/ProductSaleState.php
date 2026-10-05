<?php

namespace App\Services\Resellers\Products;

/**
 * وضعیت فروشِ یک محصول در فروشگاه یک نماینده (B5.3).
 *
 * فقط یک وضعیت درست است و ترتیب بررسی هم‌راستا با ResellerPricingService::assertSellable است:
 * نمایندگی ⟵ سبد در Core ⟵ سبد در فروشگاه من ⟵ قیمت/فعال‌سازی. تعریف SQLیِ هر وضعیت فقط در
 * ResellerProductCatalog::applyState() است و هم‌ارزی‌اش با isSellable() تست می‌شود.
 */
enum ProductSaleState: string
{
    case Selling = 'selling';
    case Paused = 'paused';
    case Unpriced = 'unpriced';
    case CategoryOff = 'category_off';
    case CategoryInactive = 'category_inactive';
    case ResellerInactive = 'reseller_inactive';

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /** مقدار نامعتبر (مثلاً از URL) ⇒ null، نه خطا */
    public static function fromInput(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Selling => 'در حال فروش',
            self::Paused => 'غیرفعال (توسط شما)',
            self::Unpriced => 'قیمت‌گذاری نشده',
            self::CategoryOff => 'سبد در فروشگاه شما خاموش است',
            self::CategoryInactive => 'سبد در سیستم اصلی غیرفعال است',
            self::ResellerInactive => 'نمایندگی غیرفعال است',
        };
    }

    /** توضیح کوتاه برای کاربر: چرا این وضعیت است و چه باید کرد */
    public function hint(): string
    {
        return match ($this) {
            self::Selling => 'مشتریان می‌توانند این محصول را بخرند.',
            self::Paused => 'قیمت شما ثبت است ولی فروش خاموش است. با «فعال کردن» دوباره به فروش می‌رود.',
            self::Unpriced => 'هنوز قیمت فروش تعیین نکرده‌اید. با «تنظیم قیمت» محصول فعال می‌شود.',
            self::CategoryOff => 'سبد این محصول را در «سبدهای فروش» خاموش کرده‌اید؛ تا روشن نشود فروش نمی‌رود.',
            self::CategoryInactive => 'مدیر اصلی این سبد را غیرفعال کرده است؛ تا فعال نشود فروش نمی‌رود.',
            self::ResellerInactive => 'تا فعال‌شدن نمایندگی هیچ محصولی فروش نمی‌رود.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Selling => 'success',
            self::Paused => 'gray',
            self::Unpriced => 'warning',
            self::CategoryOff, self::CategoryInactive, self::ResellerInactive => 'danger',
        };
    }

    public function isSelling(): bool
    {
        return $this === self::Selling;
    }

    /**
     * تصمیم خالص از روی واقعیت‌ها (بدون DB). فقط ترتیب اولویت اینجاست.
     *
     * @param  bool|null  $priceEnabled  null = رکورد قیمتی وجود ندارد
     */
    public static function resolve(
        bool $resellerActive,
        bool $categoryActive,
        bool $categoryEnabledByReseller,
        ?bool $priceEnabled,
    ): self {
        return match (true) {
            ! $resellerActive => self::ResellerInactive,
            ! $categoryActive => self::CategoryInactive,
            ! $categoryEnabledByReseller => self::CategoryOff,
            $priceEnabled === null => self::Unpriced,
            $priceEnabled === false => self::Paused,
            default => self::Selling,
        };
    }
}
