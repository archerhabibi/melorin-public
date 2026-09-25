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
            پچ 3.2.1 — W2 بند ۳: Checkout مستقیم بدون Cart، بدون
            Guest (Guest Checkout فاز W3 است، هنوز نیست). کاربر مهمان
            به login هدایت می‌شود؛ بعد از ورود Laravel خودش او را به
            همین صفحه برمی‌گرداند (intended URL استاندارد).
        --}}
        @auth
            <a href="{{ $store->isReseller()
                    ? route('website.store.checkout.show', ['slug' => $store->reseller->slug, 'product' => $product->id])
                    : route('website.checkout.show', $product->id) }}"
               class="mt-6 inline-block px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                خرید این تعرفه
            </a>
        @else
            <a href="{{ $store->isReseller()
                    ? route('website.store.login', $store->reseller->slug)
                    : route('website.login') }}"
               class="mt-6 inline-block px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                برای خرید وارد شوید
            </a>
        @endauth
    </div>
@endsection
