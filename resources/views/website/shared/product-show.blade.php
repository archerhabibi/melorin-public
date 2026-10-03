@extends('website.layouts.app')

@section('title', $product->name)

@section('content')
    <a href="{{ $store->isReseller() ? route('website.store.home', $store->reseller->slug) : route('website.home') }}" class="text-sm text-muted">&rarr; بازگشت به تعرفه‌ها</a>

    <div class="mt-4 bg-surface border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">{{ $product->name }}</h1>

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>مدت زمان</dt><dd>{{ $product->duration_days }} روز</dd></div>
            @if($product->traffic_gb)
                <div class="flex justify-between"><dt>حجم</dt><dd>{{ $product->traffic_gb }} گیگابایت</dd></div>
            @endif
            @if($product->protocol)
                <div class="flex justify-between"><dt>پروتکل</dt><dd>{{ $product->protocol->name }}</dd></div>
            @endif
        </dl>

        <div class="text-brand mt-6 text-2xl font-bold">
            {{ \App\Support\Money::format($price) }}
        </div>

        {{--
            Checkout مستقیم بدون Cart. کاربرِ وارد‌شده مستقیم به Checkout
            می‌رود؛ مهمان از مسیر Guest Checkout (فقط Email الزامی) و بعد
            Login/Register ادامه می‌دهد و Laravel او را به همین خرید
            برمی‌گرداند (intended URL استاندارد).
        --}}
        @auth
            <a href="{{ $store->isReseller()
                    ? route('website.store.checkout.show', ['slug' => $store->reseller->slug, 'product' => $product->id])
                    : route('website.checkout.show', $product->id) }}"
               class="bg-brand mt-6 inline-block px-4 py-2 rounded text-on-brand text-sm font-medium">
                خرید این تعرفه
            </a>
        @else
            <a href="{{ $store->isReseller()
                    ? route('website.store.login', $store->reseller->slug)
                    : route('website.login') }}"
               class="bg-brand mt-6 inline-block px-4 py-2 rounded text-on-brand text-sm font-medium">
                برای خرید وارد شوید
            </a>
            <span class="mt-6 mr-2 inline-block">
                @include('website.guest.entry-link', ['product' => $product, 'store' => $store])
            </span>
        @endauth
    </div>
@endsection
