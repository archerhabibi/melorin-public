@extends('website.layouts.app')

@section('title', $product->name)

@section('content')
    <a href="{{ $route('home') }}" class="text-sm text-muted">&rarr; بازگشت به تعرفه‌ها</a>

    <div class="mt-4 bg-surface border rounded-lg p-6 max-w-xl">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h1 class="text-xl font-bold">{{ $product->name }}</h1>
            @if($item->isSoldOut())
                <x-ui.badge tone="danger">ظرفیت تکمیل</x-ui.badge>
            @elseif($item->isLowStock())
                <x-ui.badge tone="warning">{{ $item->remaining() }} عدد باقی مانده</x-ui.badge>
            @endif
        </div>
        <p class="mt-1 text-xs text-muted">{{ $item->category->name }}</p>

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>مدت زمان</dt><dd>{{ $item->durationLabel() }}</dd></div>
            <div class="flex justify-between"><dt>حجم</dt><dd>{{ $item->trafficLabel() }}</dd></div>
            @if($product->protocol)
                <div class="flex justify-between"><dt>پروتکل</dt><dd>{{ $product->protocol->name }}</dd></div>
            @endif
        </dl>

        <div class="text-brand mt-6 text-2xl font-bold">
            {{ \App\Support\Money::format($price) }}
        </div>

        @if($item->isSoldOut())
            {{-- B4.1: ظرفیت تکمیل دیده می‌شود ولی خرید ممکن نیست (مرجع واقعی: PurchaseGuard / رزرو اتمیک). --}}
            <x-ui.alert type="warning" class="mt-6">ظرفیت فروش این تعرفه تکمیل شده است.</x-ui.alert>
        @else
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
        @endif
    </div>

    @if($alternatives->isNotEmpty())
        <section class="mt-8 max-w-xl" aria-labelledby="alt-title">
            <h2 id="alt-title" class="mb-3 text-sm font-semibold">تعرفه‌های جایگزین</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach($alternatives as $alt)
                    @include('website.shared._catalog-card', ['item' => $alt, 'route' => $route])
                @endforeach
            </div>
        </section>
    @endif
@endsection
