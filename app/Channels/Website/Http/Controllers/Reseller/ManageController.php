<?php

namespace App\Channels\Website\Http\Controllers\Reseller;

use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Services\Resellers\Branding\ResellerBrandingService;
use App\Services\Resellers\Branding\StoreBrandResolver;
use App\Services\Resellers\ResellerCustomerService;
use App\Services\Resellers\ResellerPricingService;
use App\Services\Resellers\ResellerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Reseller Management سبک روی وب: مشاهده‌ی Customerها/Walletها،
 * Enable/Disable Product، مدیریت Pricingهای مجاز، و ویرایش Branding
 * (Website Contract، بخش Reseller Management).
 *
 * این کنترلر هیچ Business Rule خودش ندارد («enforcement کامل از Core؛
 * Website فقط UI است») — همان `ResellerPricingService`/
 * `ResellerCustomerService`/`WalletService`ی را صدا می‌زند که پنل
 * Filament نماینده هم استفاده می‌کند.
 *
 * این کنترلر عمداً پشتِ `store.customer` نیست: خودِ نماینده لازم نیست
 * «مشتریِ» فروشگاهِ خودش باشد تا بتواند مدیریتش کند؛ فقط `auth` +
 * عضویتِ او در `reseller_admins` (هر Role، نه فقط owner — دقیقاً همان
 * دروازه‌ای که `ResellerPanelProvider::canAccessTenant` هم استفاده
 * می‌کند) لازم است.
 */
class ManageController
{
    public function __construct(
        protected ResellerPricingService $pricing,
        protected ResellerCustomerService $customerService,
        protected ResellerService $resellers,
        protected WalletService $wallet,
        protected ResellerBrandingService $branding,
        protected StoreBrandResolver $brands,
    ) {}

    /**
     * دروازه‌ی مشترکِ همه‌ی متدهای این کنترلر. جدا از Middleware نوشته
     * شده چون این چک به‌طور ذاتی وابسته به «نماینده‌ی همین Request» است
     * (از StoreContext تزریق‌شده)، نه یک قانونِ عمومیِ قابل‌استفاده‌ی
     * مجدد در جای دیگر — یک Middleware اختصاصی برای یک کنترلر، سربار
     * بی‌دلیل اضافه می‌کرد.
     */
    protected function authorize(StoreContext $store): Reseller
    {
        abort_unless($store->isReseller(), 404);

        $reseller = $store->reseller;

        abort_unless(
            auth()->check() && $this->resellers->isAdminOf($reseller, auth()->user()),
            403,
            'شما به مدیریت این فروشگاه دسترسی ندارید.'
        );

        return $reseller;
    }

    public function index(StoreContext $store): RedirectResponse
    {
        $this->authorize($store);

        return redirect()->route('website.store.manage.customers', $store->reseller->slug);
    }

    /** بند ۴۹: «مشاهده‌ی Customerها/Walletها» — فقط نمایش، بدون هیچ اقدامِ تغییردهنده. */
    public function customers(StoreContext $store): View
    {
        $reseller = $this->authorize($store);

        $customers = $this->customerService->customersQuery($reseller)
            ->orderBy('full_name')
            ->paginate(20);

        $customers->getCollection()->each(function ($user) use ($store) {
            $user->websiteWalletBalance = $this->wallet->balanceIn($user, $store);
        });

        return view('website.reseller.manage.customers', [
            'store' => $store,
            'reseller' => $reseller,
            'customers' => $customers,
        ]);
    }

    /** بند ۴۹: «Enable/Disable Product» + «مدیریت Pricingهای مجاز». */
    public function products(StoreContext $store): View
    {
        $reseller = $this->authorize($store);

        // قیمتِ «ذخیره‌شده» صرف‌نظر از فعال/غیرفعال بودن (نه
        // Product::customersPrice که برای ردیفِ غیرفعال null برمی‌گرداند):
        // بدون این، محصولی که نماینده موقتاً غیرفعال کرده قیمتِ قبلی‌اش را
        // در فرم نشان نمی‌داد و دکمه‌ی «فعال کردن» هرگز ظاهر نمی‌شد.
        $storedPrices = ResellerProductPrice::query()
            ->where('reseller_id', $reseller->id)
            ->pluck('customers_price', 'product_id');

        $products = Product::query()
            ->where('status', 'active')
            ->whereHas('category', fn ($q) => $q->where('available_to_resellers', true))
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($reseller, $storedPrices) {
                return [
                    'product' => $product,
                    'is_enabled' => $this->pricing->isSellable($reseller, $product),
                    'customers_price' => $storedPrices->has($product->id) ? (int) $storedPrices[$product->id] : null,
                    'reseller_price' => $product->resellerPrice(),
                    // بند ۴۳: حاشیه‌ی سود صرفاً نمایشی است (customers_price - reseller_price)،
                    // نه یک عدد ذخیره‌شده‌ی جدا — دقیقاً طبق قرارداد Core.
                ];
            });

        return view('website.reseller.manage.products', [
            'store' => $store,
            'reseller' => $reseller,
            'rows' => $products,
        ]);
    }

    public function setPrice(Request $request, Product $product, StoreContext $store): RedirectResponse
    {
        $reseller = $this->authorize($store);

        $data = $request->validate([
            'customers_price' => ['required', function (string $attribute, mixed $value, \Closure $fail) {
                $minor = Money::parse((string) $value);

                if ($minor === null) {
                    $fail(Money::decimals() === 0
                        ? 'قیمت باید عدد صحیح باشد.'
                        : 'قیمت نامعتبر است (حداکثر '.Money::decimals().' رقم اعشار).');
                } elseif ($minor < 0) {
                    $fail('قیمت نمی‌تواند منفی باشد.');
                }
            }],
        ]);

        try {
            $this->pricing->setCustomersPrice($reseller, $product, Money::toMinor((string) $data['customers_price']));
        } catch (InvalidArgumentException $e) {
            // پیامِ این استثنا خودش از قبل یک قاعده‌ی تجاریِ قابل‌نمایش
            // به کاربر است (نه یک خطای داخلی) — دقیقاً همان چیزی که
            // پنل Filament نماینده هم مستقیم نشان می‌دهد، نه از طریق
            // CoreErrorMapper (که برای خطاهای عمومی‌تر است).
            return back()->withErrors(['customers_price' => $e->getMessage()])->withInput();
        }

        return back()->with('status', 'قیمت ثبت و محصول فعال شد.');
    }

    public function enable(Product $product, StoreContext $store): RedirectResponse
    {
        $reseller = $this->authorize($store);

        $price = $product->resellerPrices()->where('reseller_id', $reseller->id)->first()?->customers_price;

        if ($price === null) {
            return back()->withErrors(['product' => 'ابتدا برای این محصول یک قیمت تعیین کنید.']);
        }

        try {
            $this->pricing->setCustomersPrice($reseller, $product, (int) $price);
        } catch (InvalidArgumentException $e) {
            // قیمتِ قبلاً ذخیره‌شده ممکن است با قوانینِ مرکزیِ امروز دیگر
            // مجاز نباشد (مثلاً ادمین سقف را تغییر داده)؛ فعال‌سازیِ
            // بی‌سروصدا با یک قیمتِ نامعتبر درست نیست.
            return back()->withErrors(['product' => 'قیمت قبلی دیگر مجاز نیست: '.$e->getMessage().' لطفاً قیمت جدیدی وارد کنید.']);
        }

        return back()->with('status', 'محصول برای فروشگاه شما فعال شد.');
    }

    public function disable(Product $product, StoreContext $store): RedirectResponse
    {
        $reseller = $this->authorize($store);

        $this->pricing->disable($reseller, $product);

        return back()->with('status', 'محصول برای فروشگاه شما غیرفعال شد.');
    }

    /** بند ۴۶: مشاهده/ویرایشِ Branding (نام نمایشی، لوگو، رنگ، تماس) + تنظیمات White Label (B5.7: ایندکس/توضیح متا). */
    public function branding(StoreContext $store): View
    {
        $reseller = $this->authorize($store);

        return view('website.reseller.manage.branding', [
            'store' => $store,
            'reseller' => $reseller,
            'brand' => $this->brands->forReseller($reseller),
            'setting' => $this->branding->setting($reseller),
            'readiness' => $this->branding->readiness($reseller),
        ]);
    }

    public function updateBranding(Request $request, StoreContext $store): RedirectResponse
    {
        $reseller = $this->authorize($store);

        $data = $request->validate($this->branding->rules());

        // هیچ Business Rule اینجا نیست: پاک‌سازی، لوگو، تشخیص «بدون تغییر» و Audit همه در Core است.
        $changed = $this->branding->update(
            $reseller,
            $request->user(),
            $data,
            $request->file('logo'),
            (bool) ($data['remove_logo'] ?? false),
        );

        return back()->with('status', $changed === []
            ? 'تغییری برای ذخیره وجود نداشت.'
            : 'اطلاعات فروشگاه به‌روزرسانی شد.');
    }
}
