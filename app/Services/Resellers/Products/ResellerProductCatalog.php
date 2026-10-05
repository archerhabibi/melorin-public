<?php

namespace App\Services\Resellers\Products;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerCategorySetting;
use App\Models\ResellerProductPrice;
use App\Services\Resellers\Dashboard\ResellerDashboardService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * کاتالوگ محصولات نماینده (B5.3): فهرست، وضعیت فروش، حاشیه‌ی سود، خلاصه و پرونده‌ی هر محصول — از یک منبع در Core.
 *
 * قواعد (هم‌راستا با B5.1/B5.2):
 *  - فقط‌خواندنی: هیچ‌چیز نمی‌نویسد. نوشتن (قیمت/فعال‌سازی) فقط از ResellerPricingService و ResellerBulkPricing.
 *  - «محصول قابل‌مدیریت» = محصول فعال در سیستم اصلی در سبدی که برای نمایندگان باز است (Category::available_to_resellers).
 *    سبدِ بسته‌شده توسط Core اصلاً دیده نمی‌شود؛ سبدِ غیرفعال یا خاموش‌شده دیده می‌شود ولی با وضعیت و علت.
 *  - تعریف «قابل فروش» دوباره‌نویسی نشده: ترتیب وضعیت‌ها همان assertSellable است و هم‌ارزی‌اش تست می‌شود.
 *  - بدون N+1: قیمت من، سود، تعداد فروش و تنظیم سبد Subquery روی همان Query‌اند.
 *  - همه‌ی مبالغ int (Minor Unit). فروش = سفارش paid/account_created همین نماینده (هم‌تعریف با B5.1) و شامل تمدید است.
 */
class ResellerProductCatalog
{
    public const REVENUE_STATUSES = ResellerDashboardService::REVENUE_STATUSES;

    public const HISTORY_LIMIT = 10;

    public const HISTORY_ACTIONS = ['product.price_changed', 'product.disabled'];

    /**
     * محصولات قابل‌مدیریت این نماینده با ستون‌های محاسبه‌شده:
     * supply_price، my_price (null = قیمتی ثبت نشده)، my_enabled، profit (null بدون قیمت)،
     * category_enabled (null = رکوردی نیست = روشن)، sales_count، last_sold_at.
     */
    public function query(Reseller $reseller): Builder
    {
        $supply = 'coalesce(products.reseller_price, products.main_price)';

        return $this->eligible()
            ->select('products.*')
            ->with('category')
            ->selectRaw("{$supply} as supply_price")
            ->selectSub($this->priceRow($reseller)->select('customers_price'), 'my_price')
            ->selectSub($this->priceRow($reseller)->select('is_enabled'), 'my_enabled')
            ->selectSub($this->priceRow($reseller)->selectRaw("customers_price - {$supply}"), 'profit')
            ->selectSub($this->categorySetting($reseller)->select('is_enabled'), 'category_enabled')
            ->selectSub($this->settledOrders($reseller)->selectRaw('count(*)'), 'sales_count')
            ->selectSub($this->settledOrders($reseller)->selectRaw('max(created_at)'), 'last_sold_at');
    }

    /** محصولات قابل‌مدیریت بدون ستون محاسبه‌شده (پایه‌ی query() و شمارنده‌ها) */
    private function eligible(): Builder
    {
        return Product::query()
            ->where('products.status', 'active')
            ->whereHas('category', fn ($q) => $q->where('available_to_resellers', true));
    }

    /** وضعیت فروش یک سطرِ query(). تصمیم نهایی همان ProductSaleState::resolve است. */
    public function stateOf(Reseller $reseller, Product $row): ProductSaleState
    {
        return ProductSaleState::resolve(
            resellerActive: $reseller->isActive(),
            categoryActive: $row->category?->status === 'active',
            categoryEnabledByReseller: ($row->getAttribute('category_enabled') ?? 1) ? true : false,
            priceEnabled: $row->getAttribute('my_enabled') === null ? null : (bool) $row->getAttribute('my_enabled'),
        );
    }

    /** وضعیت فروش یک محصول؛ null اگر محصول قابل‌مدیریت این نماینده نیست */
    public function stateFor(Reseller $reseller, Product $product): ?ProductSaleState
    {
        $row = $this->query($reseller)->whereKey($product->getKey())->first();

        return $row ? $this->stateOf($reseller, $row) : null;
    }

    /** حاشیه‌ی سود یک سطر؛ null اگر قیمتی ثبت نشده */
    public function marginOf(Product $row): ?ProductMargin
    {
        $price = $row->getAttribute('my_price');

        return $price === null ? null : ProductMargin::of((int) $price, (int) $row->getAttribute('supply_price'));
    }

    /**
     * فیلتر وضعیت روی query() (یا هر کوئری Product). تنها تعریف SQLیِ وضعیت‌هاست؛ شمارنده‌ی خلاصه و
     * فیلتر جدول هر دو از همین‌جا می‌آیند. ترتیب: نمایندگی ⟵ سبد Core ⟵ سبد من ⟵ قیمت.
     */
    public function applyState(Builder $query, Reseller $reseller, ProductSaleState $state): Builder
    {
        if (! $reseller->isActive()) {
            return $state === ProductSaleState::ResellerInactive ? $query : $query->whereRaw('1 = 0');
        }

        $categoryInactive = fn ($q) => $q->where('status', '<>', 'active');
        $categoryActive = fn ($q) => $q->where('status', 'active');
        $off = fn () => $this->categorySetting($reseller)->where('reseller_category_settings.is_enabled', false)->toBase();

        return match ($state) {
            ProductSaleState::ResellerInactive => $query->whereRaw('1 = 0'),
            ProductSaleState::CategoryInactive => $query->whereHas('category', $categoryInactive),
            ProductSaleState::CategoryOff => $query->whereHas('category', $categoryActive)->whereExists($off()),
            ProductSaleState::Unpriced => $this->whenCategoryOpen($query, $reseller)
                ->whereNotExists($this->priceRow($reseller)->toBase()),
            ProductSaleState::Paused => $this->whenCategoryOpen($query, $reseller)
                ->whereExists($this->priceRow($reseller)->where('reseller_product_prices.is_enabled', false)->toBase()),
            ProductSaleState::Selling => $this->whenCategoryOpen($query, $reseller)
                ->whereExists($this->priceRow($reseller)->where('reseller_product_prices.is_enabled', true)->toBase()),
        };
    }

    /** خلاصه‌ی فهرست؛ تعداد کوئری ثابت است. */
    public function summary(Reseller $reseller): ProductCatalogSummary
    {
        $count = fn (?ProductSaleState $s): int => $s === null
            ? $this->eligible()->count()
            : $this->applyState($this->eligible(), $reseller, $s)->count();

        $total = $count(null);
        $selling = $count(ProductSaleState::Selling);
        $paused = $count(ProductSaleState::Paused);
        $unpriced = $count(ProductSaleState::Unpriced);

        $supply = 'coalesce(products.reseller_price, products.main_price)';
        $stats = $this->applyState(
            $this->eligible()
                ->join('reseller_product_prices as rp', fn ($j) => $j->on('rp.product_id', '=', 'products.id')->where('rp.reseller_id', $reseller->id)),
            $reseller,
            ProductSaleState::Selling,
        )->toBase()
            ->selectRaw("coalesce(sum(rp.customers_price - {$supply}), 0) as profit_sum")
            ->selectRaw("coalesce(sum(case when rp.customers_price - {$supply} = 0 then 1 else 0 end), 0) as zero_profit")
            ->first();

        return new ProductCatalogSummary(
            total: $total,
            selling: $selling,
            paused: $paused,
            unpriced: $unpriced,
            blocked: $total - $selling - $paused - $unpriced,
            averageProfit: $selling > 0 ? intdiv((int) $stats->profit_sum * 2 + $selling, 2 * $selling) : null,
            zeroProfit: (int) $stats->zero_profit,
        );
    }

    /** پرونده‌ی یک محصول؛ null اگر محصول قابل‌مدیریت این نماینده نیست (صفحه 404 می‌دهد) */
    public function profile(Reseller $reseller, Product $product): ?ProductPricingProfile
    {
        $row = $this->query($reseller)->whereKey($product->getKey())->first();

        if (! $row) {
            return null;
        }

        $sales = $this->settledOrdersOf($reseller)->where('orders.product_id', $row->id)
            ->selectRaw('count(*) as c, coalesce(sum(customers_price), 0) as revenue')
            ->selectRaw('coalesce(sum(case when reseller_price is not null then customers_price - reseller_price else 0 end), 0) as profit')
            ->first();

        $price = $row->getAttribute('my_price');

        return new ProductPricingProfile(
            product: $row,
            state: $this->stateOf($reseller, $row),
            supplyPrice: (int) $row->getAttribute('supply_price'),
            customersPrice: $price === null ? null : (int) $price,
            priceEnabled: (bool) $row->getAttribute('my_enabled'),
            margin: $this->marginOf($row),
            bounds: PriceBounds::for($reseller, $row),
            salesCount: (int) $sales->c,
            salesRevenue: (int) $sales->revenue,
            salesProfit: (int) $sales->profit,
            lastSoldAt: $row->getAttribute('last_sold_at') ? Carbon::parse($row->getAttribute('last_sold_at')) : null,
            history: $this->history($reseller, $row),
        );
    }

    /**
     * سابقه‌ی تغییر قیمت/غیرفعال‌سازی همین محصول توسط همین نماینده (از Audit؛ فقط عددها، نه IP/کنشگر).
     *
     * @return Collection<int, array{action: string, at: CarbonInterface, from: ?int, to: ?int}>
     */
    public function history(Reseller $reseller, Product $product): Collection
    {
        return AuditLog::query()
            ->where('target_type', $product->getMorphClass())
            ->where('target_id', $product->getKey())
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->where('after->reseller_id', $reseller->id)
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'at' => $log->created_at,
                'from' => isset($log->before['customers_price']) ? (int) $log->before['customers_price'] : null,
                'to' => isset($log->after['customers_price']) ? (int) $log->after['customers_price'] : null,
            ]);
    }

    private function whenCategoryOpen(Builder $query, Reseller $reseller): Builder
    {
        return $query
            ->whereHas('category', fn ($q) => $q->where('status', 'active'))
            ->whereNotExists($this->categorySetting($reseller)->where('reseller_category_settings.is_enabled', false)->toBase());
    }

    /** رکورد قیمتِ همین نماینده برای محصولِ بیرونی (Correlated) */
    private function priceRow(Reseller $reseller): Builder
    {
        return ResellerProductPrice::query()
            ->whereColumn('reseller_product_prices.product_id', 'products.id')
            ->where('reseller_product_prices.reseller_id', $reseller->id);
    }

    /** تنظیم سبدِ همین نماینده برای سبدِ محصولِ بیرونی (Correlated) */
    private function categorySetting(Reseller $reseller): Builder
    {
        return ResellerCategorySetting::query()
            ->whereColumn('reseller_category_settings.category_id', 'products.category_id')
            ->where('reseller_category_settings.reseller_id', $reseller->id);
    }

    /** سفارش‌های قطعیِ همین نماینده برای محصولِ بیرونی (Correlated) */
    private function settledOrders(Reseller $reseller): Builder
    {
        return $this->settledOrdersOf($reseller)->whereColumn('orders.product_id', 'products.id');
    }

    /** سفارش‌های قطعیِ همین نماینده (همه‌ی محصولات) */
    private function settledOrdersOf(Reseller $reseller): Builder
    {
        return Order::query()
            ->where('orders.reseller_id', $reseller->id)
            ->whereIn('orders.status', self::REVENUE_STATUSES);
    }
}
