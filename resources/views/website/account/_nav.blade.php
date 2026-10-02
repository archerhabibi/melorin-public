{{--
    نوار زیرمنوی پنل کاربری. عمداً یک partial محلی زیر views/website/account/
    است، نه بخشی از layouts/app.blade.php: Layout مشترک فقط Extend می‌شود،
    نه بازطراحی. هر صفحه‌ی پنل Layout را @extends می‌کند و این نوار را
    داخل ناحیه‌ی محتوای خودش include می‌کند.
--}}
@php
    $accountNavRoute = fn (string $name) => $store->isReseller()
        ? route('website.store.'.$name, $store->reseller->slug)
        : route('website.'.$name);
@endphp

<nav class="mb-6 flex gap-4 text-sm border-b pb-3">
    <a href="{{ $accountNavRoute('wallet.show') }}"
       class="{{ request()->routeIs('*wallet.show') ? 'font-bold text-gray-900' : 'text-gray-500 hover:text-gray-900' }}">
        کیف‌پول
    </a>
    <a href="{{ $accountNavRoute('orders.index') }}"
       class="{{ request()->routeIs('*orders.index') ? 'font-bold text-gray-900' : 'text-gray-500 hover:text-gray-900' }}">
        سفارش‌ها
    </a>
    <a href="{{ $accountNavRoute('accounts.index') }}"
       class="{{ request()->routeIs('*accounts.*') ? 'font-bold text-gray-900' : 'text-gray-500 hover:text-gray-900' }}">
        اکانت‌های من
    </a>
    <a href="{{ $accountNavRoute('referral.show') }}"
       class="{{ request()->routeIs('*referral.show') ? 'font-bold text-gray-900' : 'text-gray-500 hover:text-gray-900' }}">
        دعوت از دوستان
    </a>
</nav>
