<?php

namespace App\Services\Resellers;

use App\Exceptions\ProductNotSellableException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerCategorySetting;
use App\Models\ResellerProductPrice;
use App\Services\Core\AuditService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * ResellerPricingService — طبق سند معماری، بخش ۳ (ResellerPricingService)
 * و سند نیازمندی بند ۵-۶: نماینده Catalog مستقل ندارد؛ فقط روی محصولِ
 * مجازِ سیستم اصلی می‌تواند «فعال/غیرفعال» و «قیمت فروش» تعیین کند، آن
 * هم در چهارچوب قوانین مرکزی (Reseller::min_sale_price_rule).
 */
class ResellerPricingService
{
    /**
     * تعیین/به‌روزرسانی قیمت فروش یک محصول برای یک نماینده. محصول را
     * به‌صورت ضمنی فعال هم می‌کند (چون تعیین قیمت بدون فعال‌سازی معنا
     * ندارد) — برای غیرفعال‌کردن صرف، از disable() استفاده شود.
     *
     * @throws InvalidArgumentException اگر قیمت یا سودِ حاصل خارج از محدوده‌ی مجاز مرکزی باشد
     */
    public function setSellingPrice(Reseller $reseller, Product $product, float $sellingPrice): ResellerProductPrice
    {
        $this->assertPriceAllowed($reseller, $product, $sellingPrice);

        $previous = ResellerProductPrice::query()
            ->where('reseller_id', $reseller->id)
            ->where('product_id', $product->id)
            ->first();

        $setting = ResellerProductPrice::query()->updateOrCreate(
            ['reseller_id' => $reseller->id, 'product_id' => $product->id],
            ['custom_price' => $sellingPrice, 'is_enabled' => true],
        );

        app(AuditService::class)->record(
            'product.price_changed',
            $product,
            before: ['custom_price' => $previous?->custom_price, 'is_enabled' => $previous?->is_enabled],
            after: ['custom_price' => $sellingPrice, 'is_enabled' => true, 'reseller_id' => $reseller->id],
            actor: $reseller,
        );

        return $setting;
    }

    public function disable(Reseller $reseller, Product $product): void
    {
        ResellerProductPrice::query()
            ->where('reseller_id', $reseller->id)
            ->where('product_id', $product->id)
            ->update(['is_enabled' => false]);

        app(AuditService::class)->record(
            'product.disabled',
            $product,
            after: ['is_enabled' => false, 'reseller_id' => $reseller->id],
            actor: $reseller,
        );
    }

    /**
     * طبق درخواست صریح: مدیر Core باید بتواند یک سبد فروش را برای
     * همه‌ی نمایندگان یک‌جا غیرفعال کند (Category::available_to_resellers).
     * این کلید سراسری بالادستِ تنظیم فی‌نفسه‌ی هر نماینده است — یعنی
     * حتی اگر نماینده‌ای خودش محصول را فعال/قیمت‌گذاری کرده باشد،
     * وقتی سبدش سراسری غیرفعال شود، دیگر قابل‌فروش نیست.
     */
    /**
     * دروازه‌ی مرکزی «آیا این نماینده حق فروش این محصول را دارد؟»
     *
     * دلیل وجود این متد (P0 گزارش امنیتی v3.0.6): تا پیش از این، قوانین
     * sellable فقط در لایه‌ی UI/Bot اعمال می‌شد — یعنی
     * sellableProducts() محصول را نشان نمی‌داد، ولی اگر کسی یک callback
     * دست‌ساز مثل «rbuy:product:123» می‌فرستاد، AccountService فقط
     * sellingPriceForReseller() را چک می‌کرد که از وضعیت سبد فروش و
     * فعال‌بودن نماینده بی‌خبر است. نتیجه: سبدِ بسته‌شده (چه توسط Core و
     * چه توسط خودِ نماینده) و حتی نماینده‌ی غیرفعال، همچنان قابل خرید
     * بود.
     *
     * اصل حاکم: UI هرگز مرز امنیتی نیست. هر مسیری که به خرید/تمدید
     * منتهی می‌شود باید از همین یک متد عبور کند.
     *
     * @throws ProductNotSellableException
     */
    public function assertSellable(Reseller $reseller, Product $product): void
    {
        if (! $reseller->isActive()) {
            throw new ProductNotSellableException('این نمایندگی غیرفعال است.');
        }

        if ($product->status !== 'active') {
            throw new ProductNotSellableException('این محصول فعال نیست.');
        }

        $category = $product->category;

        if (! $category || $category->status !== 'active') {
            throw new ProductNotSellableException('سبد فروش این محصول فعال نیست.');
        }

        if (! $category->available_to_resellers) {
            throw new ProductNotSellableException('این سبد فروش برای نمایندگان در دسترس نیست.');
        }

        if (! $this->isCategoryEnabled($reseller, $category)) {
            throw new ProductNotSellableException('این سبد فروش در فروشگاه شما غیرفعال است.');
        }

        if ($product->sellingPriceForReseller($reseller) === null) {
            throw new ProductNotSellableException('این محصول برای این نماینده قیمت‌گذاری/فعال نشده است.');
        }
    }

    /**
     * نسخه‌ی boolean همان assertSellable — عمداً روی آن سوار شده تا این
     * دو هیچ‌وقت از هم جدا نیفتند. قبلاً منطقشان جدا نوشته شده بود و
     * دقیقاً همین باعث شد قوانین UI و Core با هم فرق کنند.
     */
    public function isSellable(Reseller $reseller, Product $product): bool
    {
        try {
            $this->assertSellable($reseller, $product);

            return true;
        } catch (ProductNotSellableException) {
            return false;
        }
    }

    /**
     * آیا این سبد فروش در ربات این نماینده نمایش داده می‌شود؟ (درخواست
     * صریح: «نماینده باید بتواند سبد فروش ربات خودش را فعال و یا غیرفعال
     * کند».) نبودِ رکورد یعنی فعال — تا نمایندگان فعلی که هیچ تنظیمی
     * ثبت نکرده‌اند، با افزودن این قابلیت ناگهان فروششان قطع نشود.
     *
     * توجه: این متد عمداً available_to_resellers را دوباره چک نمی‌کند؛
     * آن یک لایه‌ی مستقلِ بالادست است و در isSellable/sellableProducts
     * جداگانه اعمال می‌شود. یعنی اگر Core سبدی را ببندد، فعال‌بودنِ
     * محلیِ نماینده هیچ اثری ندارد.
     */
    public function isCategoryEnabled(Reseller $reseller, Category $category): bool
    {
        $setting = ResellerCategorySetting::query()
            ->where('reseller_id', $reseller->id)
            ->where('category_id', $category->id)
            ->first();

        return $setting ? $setting->is_enabled : true;
    }

    /**
     * فعال/غیرفعال کردن یک سبد فروش برای ربات همین نماینده.
     *
     * قانون سراسری (available_to_resellers) اینجا هم اعمال می‌شود، نه
     * فقط در UI (P2 گزارش امنیتی، مورد #18): پیش از این، تنها چیزی که
     * جلوی فعال‌کردن یک سبدِ سراسری‌بسته را می‌گرفت این بود که
     * CategoryResource آن را در لیست نشان نمی‌داد — و UI هیچ‌وقت یک مرز
     * امنیتی نیست. یک درخواست Livewire دست‌ساز می‌توانست از آن عبور کند.
     */
    public function setCategoryEnabled(Reseller $reseller, Category $category, bool $enabled): ResellerCategorySetting
    {
        if ($enabled && ! $category->available_to_resellers) {
            throw new InvalidArgumentException('این سبد فروش توسط مدیر اصلی برای نمایندگان غیرفعال شده است.');
        }

        $setting = ResellerCategorySetting::query()->updateOrCreate(
            ['reseller_id' => $reseller->id, 'category_id' => $category->id],
            ['is_enabled' => $enabled],
        );

        app(AuditService::class)->record(
            $enabled ? 'category.enabled' : 'category.disabled',
            $category,
            after: ['reseller_id' => $reseller->id, 'is_enabled' => $enabled],
            actor: $reseller,
        );

        return $setting;
    }

    /** لیست محصولاتی که همین الان برای این نماینده واقعاً قابل‌فروش‌اند (هم فعال در سیستم اصلی و سبدش برای نمایندگان باز باشد، هم فعال/قیمت‌گذاری‌شده توسط خودِ نماینده) */
    public function sellableProducts(Reseller $reseller): Collection
    {
        // سبدهایی که خودِ نماینده صراحتاً بسته است. چون «نبودِ رکورد =
        // فعال»، فقط رکوردهای is_enabled=false را استثنا می‌کنیم — نه
        // اینکه به whereHas مثبت تکیه کنیم، که نمایندگانِ بدون رکورد را
        // هم حذف می‌کرد.
        $disabledCategoryIds = ResellerCategorySetting::query()
            ->where('reseller_id', $reseller->id)
            ->where('is_enabled', false)
            ->pluck('category_id');

        return Product::query()
            ->where('status', 'active')
            ->whereHas('category', fn ($q) => $q->where('available_to_resellers', true))
            ->whereNotIn('category_id', $disabledCategoryIds)
            ->whereHas('resellerPrices', fn ($q) => $q->where('reseller_id', $reseller->id)->where('is_enabled', true))
            ->get();
    }

    /**
     * قوانین مرکزی قیمت‌گذاری (بند ۶ سند نیازمندی و بند ۲۱ سند نیازمندی
     * اصلیِ سیستم): حداقل/حداکثر قیمت فروش و حداقل/حداکثر سود. ساختار
     * min_sale_price_rule: ['min_price'=>?, 'max_price'=>?,
     * 'min_profit'=>?, 'max_profit'=>?] — هر کلید اختیاری است؛ کلید
     * غایب یعنی محدودیتی روی آن بعد وجود ندارد.
     */
    protected function assertPriceAllowed(Reseller $reseller, Product $product, float $sellingPrice): void
    {
        $rule = $reseller->min_sale_price_rule ?? [];
        // طبق درخواست صریح: قیمت نمایندگان (اگر ست شده) هزینه‌ی واقعیِ
        // نماینده است، نه products.price خرده‌فروشی — سود و «حداقل
        // قیمت مجاز» باید نسبت به همین عدد محاسبه شوند، وگرنه نماینده
        // هیچ‌وقت نمی‌تواند بین reseller_price و price قیمت‌گذاری کند،
        // در حالی که دقیقاً همان بازه‌ای است که باید سودآور باشد.
        $basePrice = $product->resellerBasePrice();
        $profit = $sellingPrice - $basePrice;

        if (isset($rule['min_price']) && $sellingPrice < (float) $rule['min_price']) {
            throw new InvalidArgumentException('قیمت فروش کمتر از حداقل مجاز است.');
        }

        if (isset($rule['max_price']) && $sellingPrice > (float) $rule['max_price']) {
            throw new InvalidArgumentException('قیمت فروش بیشتر از حداکثر مجاز است.');
        }

        if (isset($rule['min_profit']) && $profit < (float) $rule['min_profit']) {
            throw new InvalidArgumentException('سود این قیمت کمتر از حداقل مجاز است.');
        }

        if (isset($rule['max_profit']) && $profit > (float) $rule['max_profit']) {
            throw new InvalidArgumentException('سود این قیمت بیشتر از سقف مجاز است.');
        }

        if ($sellingPrice < $basePrice) {
            throw new InvalidArgumentException('قیمت فروش نمی‌تواند کمتر از قیمت پایه باشد.');
        }
    }
}
