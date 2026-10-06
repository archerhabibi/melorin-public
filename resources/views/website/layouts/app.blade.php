<!DOCTYPE html>
<html lang="fa" dir="rtl">
{{--
    Branding (نام نمایشی/لوگو/رنگ/تماس)
    و منوی اختصاصی نماینده. برندینگِ Main همیشه پیش‌فرض‌های ثابت است؛
    برای نماینده از StoreBrandResolver (B5.7) می‌آید که خودش fallback امن
    دارد (نمایندهٔ بدون تنظیمات هم به همان شکلِ قبلی دیده می‌شود).
--}}
@php
    // B5.7: یک منبع واحد برندینگ (StoreBrandResolver)؛ در طول Request فقط یک بار خوانده می‌شود.
    $brand = app(\App\Services\Resellers\Branding\StoreBrandResolver::class)->forContext($storeContext);
    $canManageStore = $storeContext->isReseller()
        && auth()->check()
        && app(\App\Services\Resellers\ResellerService::class)->isAdminOf($storeContext->reseller, auth()->user());
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- بند ۹.۷ (SEO): صفحات نماینده پیش‌فرض noindex مگر خودش (در تنظیمات فروشگاه) ایندکس را روشن کرده باشد (B5.7). --}}
    {{-- قاعده‌ی سخت‌گیرانه‌تر برنده است: فروشگاهِ غیرایندکس `noindex, nofollow` دارد حتی اگر صفحه `@section('robots')` بدهد. --}}
    @php($robots = $brand->robots() ?? (trim($__env->yieldContent('robots')) ?: null))
    @if($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif
    @if($brand->description())
        <meta name="description" content="{{ $brand->description() }}">
    @endif
    <meta name="theme-color" content="{{ $brand->color }}">

    <title>@yield('title', $brand->name)</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/theme-init.js') }}"></script>
    @include('website.partials.brand-head', ['brand' => $brand])
    @stack('head')
</head>
<body class="min-h-screen flex flex-col">

    {{-- B1.2: دسترس‌پذیری — پرش به محتوا برای کاربر کیبورد/Screen reader --}}
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:start-2 focus:z-50 btn btn-primary">پرش به محتوا</a>

    <header class="border-b border-border bg-surface">
        <div class="@yield('container', 'max-w-5xl') mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ $storeContext->isReseller() ? route('website.store.home', $storeContext->reseller->slug) : route('website.home') }}"
               class="text-brand font-bold text-lg flex items-center gap-2">
                {{-- B6.2: ارتفاع ثابت و عرض آزاد (لوگوی افقی کوچک/له نشود)؛ با لوگوی تیره، یکی از دو تصویر بر اساس تم نشان داده می‌شود. --}}
                @if($brand->logoUrl)
                    <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" class="brand-logo h-8 w-auto max-w-[10rem] rounded object-contain {{ $brand->hasDarkLogo() ? 'brand-logo-light' : '' }}">
                    @if($brand->hasDarkLogo())
                        <img src="{{ $brand->logoDarkUrl }}" alt="{{ $brand->name }}" class="brand-logo brand-logo-dark h-8 w-auto max-w-[10rem] rounded object-contain">
                    @endif
                @endif
                {{ $brand->name }}
            </a>

            <nav class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 text-sm" aria-label="منوی اصلی">
                {{-- بند ۴۷: منوی اختصاصی نماینده — فقط برای ادمین/مالکِ همین فروشگاه --}}
                @if($canManageStore)
                    <a href="{{ route('website.store.manage.customers', $storeContext->reseller->slug) }}" class="nav-link">مشتریان</a>
                    <a href="{{ route('website.store.manage.products', $storeContext->reseller->slug) }}" class="nav-link">محصولات</a>
                    <a href="{{ route('website.store.manage.branding', $storeContext->reseller->slug) }}" class="nav-link">تنظیمات فروشگاه</a>
                    <a href="{{ route('website.store.manage.domain', $storeContext->reseller->slug) }}" class="nav-link">دامنه</a>
                @endif

                <button type="button" data-theme-toggle class="nav-link" aria-label="تغییر حالت روشن/تیره" title="حالت روشن/تیره">
                    <x-ui.icon name="moon" :size="18" class="theme-icon-light" />
                    <x-ui.icon name="sun" :size="18" class="theme-icon-dark" />
                </button>

                @auth
                    <a href="{{ $storeContext->isReseller() ? route('website.store.dashboard', $storeContext->reseller->slug) : route('website.dashboard') }}"
                       class="nav-link flex items-center gap-1.5">
                        <x-ui.icon name="user" :size="16" />
                        <span class="hidden sm:inline">{{ auth()->user()->full_name }}</span>
                        <span class="sm:hidden">حساب من</span>
                    </a>
                    <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.logout', $storeContext->reseller->slug) : route('website.logout') }}">
                        @csrf
                        <button type="submit" class="nav-link">خروج</button>
                    </form>
                @else
                    <a href="{{ $storeContext->isReseller() ? route('website.store.login', $storeContext->reseller->slug) : route('website.login') }}" class="nav-link">ورود</a>
                    <a href="{{ $storeContext->isReseller() ? route('website.store.register', $storeContext->reseller->slug) : route('website.register') }}"
                       class="btn btn-primary btn-sm">ثبت‌نام</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1 @yield('container', 'max-w-5xl') w-full mx-auto px-4 py-6 sm:py-8">
        @if(session('status'))
            <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
        @endif

        @if(session('warning'))
            <x-ui.alert type="warning" class="mb-6">{{ session('warning') }}</x-ui.alert>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-border bg-surface py-6 text-center text-xs text-subtle">
        <div>© {{ now()->format('Y') }} {{ $brand->name }}</div>

        {{-- بند ۴۶: اطلاعات تماس نماینده، فقط اگر خودش ثبت کرده باشد --}}
        @if($brand->hasContact())
            <div class="mt-1">
                @if($brand->phone) <span>{{ $brand->phone }}</span> @endif
                @if($brand->phone && $brand->email) <span class="mx-1">·</span> @endif
                @if($brand->email) <span>{{ $brand->email }}</span> @endif
            </div>
        @endif
    </footer>
    @stack('scripts')
</body>
</html>
