<?php

namespace App\Services\Core\Store;

use App\Models\Reseller;

/**
 * بند ۶ بلوپرینت — «به‌جای پخش کردن شرط‌های if ($reseller) در کل پروژه،
 * یک مفهوم مرکزی ایجاد شود.»
 *
 * امروز منطق «این خرید مال کدام فروشگاه است؟» در ده‌ها جا تکرار شده:
 * در AccountService، در هندلرهای ربات، در سرویس‌های نماینده. هر جا که
 * یکی از این شرط‌ها فراموش یا اشتباه نوشته شود، یک باگ مالی یا نشت
 * داده بین فروشگاه‌ها به دست می‌آید — و چون شرط‌ها پراکنده‌اند، اصلاً
 * معلوم نیست چندتایشان هست.
 *
 * این یک شیء تغییرناپذیر (immutable) است: بعد از ساخته‌شدن عوض نمی‌شود،
 * پس نمی‌توان وسط یک عملیات مالی فروشگاه را زیر پای کد عوض کرد.
 */
final class StoreContext
{
    private function __construct(
        public readonly string $storeType,
        public readonly ?Reseller $reseller,
    ) {}

    public static function main(): self
    {
        return new self('main', null);
    }

    public static function reseller(Reseller $reseller): self
    {
        return new self('reseller', $reseller);
    }

    public static function fromReseller(?Reseller $reseller): self
    {
        return $reseller ? self::reseller($reseller) : self::main();
    }

    public function isMain(): bool
    {
        return $this->storeType === 'main';
    }

    public function isReseller(): bool
    {
        return $this->storeType === 'reseller';
    }

    public function resellerId(): ?int
    {
        return $this->reseller?->id;
    }

    /**
     * آیا این فروشگاه الان می‌تواند عملیات انجام دهد؟
     *
     * فروشگاه اصلی همیشه فعال است؛ فروشگاه نماینده فقط وقتی که خودِ
     * نمایندگی فعال باشد. این همان چکی است که تا نسخه‌ی ۳.۰.۷ در چند
     * مسیر جا افتاده بود و نماینده‌ی غیرفعال همچنان می‌توانست بفروشد.
     */
    public function isOperational(): bool
    {
        return $this->isMain() || $this->reseller?->status === 'active';
    }

    public function label(): string
    {
        return $this->isMain()
            ? 'فروشگاه اصلی'
            : ('نمایندگی '.($this->reseller->slug ?? $this->reseller->id));
    }

    /** برای ثبت در payload عملیات و لاگ‌ها */
    public function toArray(): array
    {
        return [
            'store_type' => $this->storeType,
            'reseller_id' => $this->resellerId(),
        ];
    }

    public function equals(self $other): bool
    {
        return $this->storeType === $other->storeType
            && $this->resellerId() === $other->resellerId();
    }
}
