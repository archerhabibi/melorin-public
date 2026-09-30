{{--
    پچ 3.2.4 — بند ۱۷ Roadmap (وابستگی نفر ۳ به نفر ۱): این پارشیال
    همان «رابط قابل‌استفاده‌ی مجدد» است که نفر ۱ باید زود منتشر کند تا
    نفر ۳ (فروشگاه نماینده) منتظر کل کار W3 نماند.

    استفاده:
        @include('website.guest.entry-link', ['product' => $product, 'store' => $store])

    فقط به $product و $store نیاز دارد؛ هیچ وابستگی دیگری به Layout یا
    CSS خاص Main ندارد، پس داخل هر Layout (از جمله Branding نماینده که
    مالکیتش با نفر ۳ است) قابل‌استفاده است.
--}}
<a href="{{ $store->isReseller()
        ? route('website.store.guest-checkout.show', ['slug' => $store->reseller->slug, 'product' => $product->id])
        : route('website.guest-checkout.show', $product->id) }}"
   class="inline-block px-4 py-2 rounded border text-sm font-medium text-gray-700">
    خرید به‌عنوان مهمان
</a>
