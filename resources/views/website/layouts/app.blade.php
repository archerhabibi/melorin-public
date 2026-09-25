<!DOCTYPE html>
<html lang="fa" dir="rtl">
{{--
    نکته‌ی پیاده‌سازی: تصمیم ۹.۴ (Branding contract: Name/Logo/رنگ اصلی)
    هنوز روی جدول resellers migrate نشده — این یک TODO مستقل و کوچک
    برای بعد از W2 است (bخش ۹.۴)، نه چیزی که این Layout بتواند همین
    الان مصرف کند. تا آن زمان فقط از StoreContext::label() (که از قبل
    در Core هست) برای نمایش نام فروشگاه استفاده می‌شود.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- بند ۹.۷ (SEO): صفحات نماینده پیش‌فرض noindex مگر خودش بخواهد. --}}
    @if(isset($storeContext) && $storeContext->isReseller())
        <meta name="robots" content="noindex, nofollow">
    @endif

    <title>@yield('title', isset($storeContext) && $storeContext->isReseller() ? $storeContext->label() : 'Melorin')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --brand: #4f46e5; }</style>
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased min-h-screen flex flex-col">

    <header class="border-b bg-white">
        <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ $storeContext->isReseller() ? route('website.store.home', $storeContext->reseller->slug) : route('website.home') }}"
               class="font-bold text-lg" style="color: var(--brand)">
                {{ $storeContext->isReseller() ? $storeContext->label() : 'Melorin' }}
            </a>

            <nav class="flex items-center gap-4 text-sm">
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
        © {{ now()->format('Y') }} {{ $storeContext->isReseller() ? $storeContext->label() : 'Melorin' }}
    </footer>
</body>
</html>
