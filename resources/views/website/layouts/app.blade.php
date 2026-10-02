<!DOCTYPE html>
<html lang="fa" dir="rtl">
{{--
    Branding (نام نمایشی/لوگو/رنگ/تماس)
    و منوی اختصاصی نماینده. برندینگِ Main همیشه پیش‌فرض‌های ثابت است؛
    برای نماینده از ResellerWebsiteSetting::brandingFor() می‌آید که خودش
    fallback امن دارد (نمایندهٔ بدون تنظیمات هم به همان شکلِ قبلی دیده
    می‌شود).
--}}
@php
    $branding = \App\Models\ResellerWebsiteSetting::brandingFor($storeContext->isReseller() ? $storeContext->reseller : null);
    $canManageStore = $storeContext->isReseller()
        && auth()->check()
        && app(\App\Services\Resellers\ResellerService::class)->isAdminOf($storeContext->reseller, auth()->user());
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- بند ۹.۷ (SEO): صفحات نماینده پیش‌فرض noindex مگر خودش بخواهد. --}}
    @if(isset($storeContext) && $storeContext->isReseller())
        <meta name="robots" content="noindex, nofollow">
    @endif

    <title>@yield('title', isset($storeContext) && $storeContext->isReseller() ? $branding['name'] : 'Melorin')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: {{ $branding['color'] }}; }</style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased min-h-screen flex flex-col">

    <header class="border-b bg-white">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ $storeContext->isReseller() ? route('website.store.home', $storeContext->reseller->slug) : route('website.home') }}"
               class="font-bold text-lg flex items-center gap-2" style="color: var(--brand)">
                @if($storeContext->isReseller() && $branding['logo_url'])
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] }}" class="h-8 w-8 rounded object-contain">
                @endif
                {{ $storeContext->isReseller() ? $branding['name'] : 'Melorin' }}
            </a>

            <nav class="flex items-center gap-4 text-sm">
                {{-- بند ۴۷: منوی اختصاصی نماینده — فقط برای ادمین/مالکِ همین فروشگاه --}}
                @if($canManageStore)
                    <a href="{{ route('website.store.manage.customers', $storeContext->reseller->slug) }}" class="text-gray-500 hover:text-gray-900">مشتریان</a>
                    <a href="{{ route('website.store.manage.products', $storeContext->reseller->slug) }}" class="text-gray-500 hover:text-gray-900">محصولات</a>
                    <a href="{{ route('website.store.manage.branding', $storeContext->reseller->slug) }}" class="text-gray-500 hover:text-gray-900">تنظیمات فروشگاه</a>
                @endif

                @auth
                    <span class="text-gray-500">{{ auth()->user()->full_name }}</span>
                    <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.logout', $storeContext->reseller->slug) : route('website.logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-gray-900">خروج</button>
                    </form>
                @else
                    <a href="{{ $storeContext->isReseller() ? route('website.store.login', $storeContext->reseller->slug) : route('website.login') }}" class="text-gray-500 hover:text-gray-900">ورود</a>
                    <a href="{{ $storeContext->isReseller() ? route('website.store.register', $storeContext->reseller->slug) : route('website.register') }}"
                       class="px-3 py-1.5 rounded text-white" style="background: var(--brand)">ثبت‌نام</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="flex-1 max-w-5xl w-full mx-auto px-4 py-8">
        @if(session('status'))
            <div class="mb-6 rounded border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t bg-white py-6 text-center text-xs text-gray-400">
        <div>© {{ now()->format('Y') }} {{ $storeContext->isReseller() ? $branding['name'] : 'Melorin' }}</div>

        {{-- بند ۴۶: اطلاعات تماس نماینده، فقط اگر خودش ثبت کرده باشد --}}
        @if($storeContext->isReseller() && ($branding['phone'] || $branding['email']))
            <div class="mt-1">
                @if($branding['phone']) <span>{{ $branding['phone'] }}</span> @endif
                @if($branding['phone'] && $branding['email']) <span class="mx-1">·</span> @endif
                @if($branding['email']) <span>{{ $branding['email'] }}</span> @endif
            </div>
        @endif
    </footer>
</body>
</html>
