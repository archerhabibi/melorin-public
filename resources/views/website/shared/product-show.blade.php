@extends('website.layouts.app')

@section('title', $product->name)

@section('content')
    <a href="{{ $store->isReseller() ? route('website.store.home', $store->reseller->slug) : route('website.home') }}" class="text-sm text-gray-500">&rarr; بازگشت به تعرفه‌ها</a>

    <div class="mt-4 bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">{{ $product->name }}</h1>

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>مدت زمان</dt><dd>{{ $product->duration_days }} روز</dd></div>
            @if($product->traffic_gb)
                <div class="flex justify-between"><dt>حجم</dt><dd>{{ $product->traffic_gb }} گیگابایت</dd></div>
            @endif
            @if($product->protocol)
                <div class="flex justify-between"><dt>پروتکل</dt><dd>{{ $product->protocol->name }}</dd></div>
            @endif
        </dl>

        <div class="mt-6 text-2xl font-bold" style="color: var(--brand)">
            {{ number_format($price) }} تومان
        </div>

        {{--
            دکمه‌ی «خرید» عمداً اینجا نیست: طبق فازبندی Roadmap (بند ۴)،
            Checkout بدون Cart (W2 بند ۳) و WebsitePurchaseFacade هنوز
            ساخته نشده‌اند — افزودنش الان یعنی دکمه‌ای که یا اصلاً کار
            نمی‌کند یا مجبورمان می‌کند منطق خرید را نصفه اینجا بنویسیم؛
            هر دو دقیقاً همان چیزی است که بند ۹۰/۹۳ منع کرده.
        --}}
        <div class="mt-6 rounded border border-dashed border-gray-300 px-4 py-3 text-sm text-gray-500">
            خرید مستقیم از سایت در فاز بعدی (W2 ادامه) فعال می‌شود.
        </div>
    </div>
@endsection
