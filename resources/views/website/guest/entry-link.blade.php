{{--
    لینک ورود به Guest Checkout؛ پارشیالِ قابل‌استفاده‌ی مجدد برای صفحه‌ی
    محصولِ Main و فروشگاه نماینده.

    استفاده:
        @include('website.guest.entry-link', ['product' => $product, 'store' => $store])

    فقط به $product و $store نیاز دارد؛ هیچ وابستگی دیگری به Layout یا
    CSS خاص Main ندارد، پس داخل هر Layout (از جمله Branding نماینده)
    قابل‌استفاده است.
--}}
<a href="{{ $store->isReseller()
        ? route('website.store.guest-checkout.show', ['slug' => $store->reseller->slug, 'product' => $product->id])
        : route('website.guest-checkout.show', $product->id) }}"
   class="inline-block px-4 py-2 rounded border text-sm font-medium text-text">
    خرید به‌عنوان مهمان
</a>
