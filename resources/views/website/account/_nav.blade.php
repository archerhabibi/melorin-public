{{--
    فاز W4 — نوار زیرمنوی پنل کاربری. عمداً یک partial محلی زیر
    views/website/account/ است، نه ویرایش layouts/app.blade.php: طبق
    بخش ۱۰ Roadmap («نقاط اشتراکی») آن Layout مالکیت نفر ۳ است و بقیه
    فقط از آن Extend می‌کنند، بازطراحی‌اش نمی‌کنند. این partial همان
    Layout مشترک را extend می‌کند (@extends در هر صفحه) و فقط داخل
    ناحیه‌ی محتوای خودش یک نوار ساده اضافه می‌کند.
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
