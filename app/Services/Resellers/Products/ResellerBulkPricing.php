<?php

namespace App\Services\Resellers\Products;

use App\Models\Product;
use App\Models\Reseller;
use App\Services\Resellers\ResellerPricingService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * قیمت‌گذاری و فعال/غیرفعال‌سازی گروهی (B5.3) — فقط روی ResellerPricingService سوار است.
 *
 * هیچ قاعده‌ی قیمتی اینجا تکرار نشده: هر محصول جداگانه از همان PriceBounds/setCustomersPrice می‌گذرد، پس Audit
 * هر محصول مثل تغییر تکی ثبت می‌شود. رفتار «ردِ صریح»: محصولی که قیمتِ محاسبه‌شده‌اش مجاز نیست **گرد/برش
 * نمی‌خورد**؛ رد می‌شود و دلیلش در نتیجه می‌آید. ورودی فقط شناسه است و هر شناسه دوباره از
 * ResellerProductCatalog::query() می‌گذرد، پس محصول بیرون از دسترسِ نماینده (سبد بسته‌شده توسط Core، غیرفعال در
 * سیستم اصلی) حتی با شناسه‌ی دست‌ساز هرگز تغییر نمی‌کند.
 */
class ResellerBulkPricing
{
    public const NOT_AVAILABLE = 'این محصول در دسترس شما نیست.';

    public function __construct(
        private readonly ResellerPricingService $pricing,
        private readonly ResellerProductCatalog $catalog,
    ) {}

    /**
     * قیمت فروش = reseller_price + سود (درصدی یا ثابت) و سپس گردکردنِ رو‌به‌بالا به مضرب $roundTo.
     * محصول هم‌زمان فعال می‌شود (همان رفتار «تنظیم قیمت» تکی).
     *
     * @param  list<int|string>  $productIds
     * @param  int  $roundTo  مضرب گردکردن در Minor Unit؛ ۱ = بدون گرد
     *
     * @throws InvalidArgumentException ورودی نامعتبر (درصد/مبلغ منفی، درصد بیش از سقف، مضرب کمتر از ۱)
     */
    public function markup(Reseller $reseller, array $productIds, MarkupMode $mode, int $value, int $roundTo = 1): BulkPricingResult
    {
        if ($value < 0) {
            throw new InvalidArgumentException('مقدار سود نمی‌تواند منفی باشد.');
        }

        if ($mode === MarkupMode::Percent && $value > MarkupMode::MAX_PERCENT) {
            throw new InvalidArgumentException('درصد سود نمی‌تواند بیش از '.MarkupMode::MAX_PERCENT.' باشد.');
        }

        if ($roundTo < 1) {
            throw new InvalidArgumentException('مضرب گردکردن باید حداقل ۱ باشد.');
        }

        return $this->each($reseller, $productIds, function (Product $row) use ($reseller, $mode, $value, $roundTo): ?string {
            $supply = (int) $row->getAttribute('supply_price');
            $profit = $mode === MarkupMode::Percent ? intdiv($supply * $value * 2 + 100, 200) : $value;
            $target = intdiv($supply + $profit + $roundTo - 1, $roundTo) * $roundTo;

            if ($violation = PriceBounds::for($reseller, $row)->violation($target)) {
                return $violation;
            }

            $this->pricing->setCustomersPrice($reseller, $row, $target);

            return null;
        });
    }

    /**
     * فعال‌سازی با قیمتِ ذخیره‌شده‌ی قبلی. بدون قیمت یا با قیمتی که دیگر مجاز نیست رد می‌شود (قیمت نامعتبر
     * بی‌صدا دوباره فعال نمی‌شود).
     *
     * @param  list<int|string>  $productIds
     */
    public function enable(Reseller $reseller, array $productIds): BulkPricingResult
    {
        return $this->each($reseller, $productIds, function (Product $row) use ($reseller): ?string {
            if ($row->getAttribute('my_price') === null) {
                return 'قیمتی برای این محصول ثبت نشده است.';
            }

            if ($row->getAttribute('my_enabled')) {
                return 'از قبل فعال است.';
            }

            try {
                $this->pricing->enable($reseller, $row);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        });
    }

    /** @param  list<int|string>  $productIds */
    public function disable(Reseller $reseller, array $productIds): BulkPricingResult
    {
        return $this->each($reseller, $productIds, function (Product $row) use ($reseller): ?string {
            if (! $row->getAttribute('my_enabled')) {
                return 'فعال نبود.';
            }

            $this->pricing->disable($reseller, $row);

            return null;
        });
    }

    /**
     * @param  list<int|string>  $productIds
     * @param  callable(Product): ?string  $apply  null = اعمال شد؛ رشته = دلیل رد
     */
    private function each(Reseller $reseller, array $productIds, callable $apply): BulkPricingResult
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $rows = $this->catalog->query($reseller)->whereKey($ids->all())->get()->keyBy('id');

        $applied = [];
        $skipped = [];

        DB::transaction(function () use ($ids, $rows, $apply, &$applied, &$skipped): void {
            foreach ($ids as $id) {
                $row = $rows->get($id);

                if (! $row) {
                    $skipped[$id] = self::NOT_AVAILABLE;

                    continue;
                }

                $reason = $apply($row);

                if ($reason === null) {
                    $applied[] = $id;
                } else {
                    $skipped[$id] = $reason;
                }
            }
        });

        return new BulkPricingResult($applied, $skipped);
    }
}
