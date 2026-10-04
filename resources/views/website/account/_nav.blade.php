{{--
    نوار زیرمنوی پنل کاربری (Customer Layout — B1.2). عمداً یک partial محلی زیر views/website/account/
    است، نه بخشی از layouts/app.blade.php: Layout مشترک فقط Extend می‌شود،
    نه بازطراحی. هر صفحه‌ی پنل Layout را @extends می‌کند و این نوار را
    داخل ناحیه‌ی محتوای خودش include می‌کند.

    قرارداد: افزودن آیتم جدید = یک ردیف در $items (route، پترن active، آیکون، برچسب).
    در موبایل افقی اسکرول می‌شود (بدون شکستن چیدمان).
--}}
@php
    // صفحاتِ پنل `$store` می‌فرستند؛ صفحه‌هایی مثل پروفایل فقط `$storeContext` (Layout) را دارند.
    $store = $store ?? $storeContext;

    $accountNavRoute = fn (string $name) => $store->isReseller()
        ? route('website.store.'.$name, $store->reseller->slug)
        : route('website.'.$name);

    $items = [
        ['route' => 'dashboard',     'active' => '*dashboard',     'icon' => 'home',     'label' => 'داشبورد'],
        ['route' => 'wallet.show',   'active' => '*wallet.show',   'icon' => 'wallet',   'label' => 'کیف‌پول'],
        ['route' => 'orders.index',  'active' => '*orders.index',  'icon' => 'orders',   'label' => 'سفارش‌ها'],
        ['route' => 'accounts.index', 'active' => '*accounts.*',   'icon' => 'server',   'label' => 'اکانت‌های من'],
        ['route' => 'tickets.index', 'active' => '*tickets.*',     'icon' => 'ticket',   'label' => 'پشتیبانی'],
        ['route' => 'referral.show', 'active' => '*referral.show', 'icon' => 'gift',     'label' => 'دعوت از دوستان'],
        ['route' => 'identity.profile.show', 'active' => '*identity.profile.*', 'icon' => 'user', 'label' => 'پروفایل'],
    ];

    // Breadcrumb خودکار: خانه › حساب من › بخش جاری [› صفحه‌ی عمیق‌تر از $crumbs]
    $activeItem = collect($items)->first(fn ($i) => request()->routeIs($i['active']));
    $deeper = $crumbs ?? [];
    $trail = [
        ['label' => 'خانه', 'url' => $store->isReseller() ? route('website.store.home', $store->reseller->slug) : route('website.home')],
        ['label' => 'حساب من', 'url' => $accountNavRoute('dashboard')],
    ];
    if ($activeItem) {
        $trail[] = ['label' => $activeItem['label'], 'url' => $deeper ? $accountNavRoute($activeItem['route']) : null];
    }
    $trail = array_merge($trail, $deeper);
@endphp

<x-ui.breadcrumb :items="$trail" />

<nav class="mb-6 -mx-4 px-4 overflow-x-auto border-b border-border" aria-label="پنل کاربری">
    <ul class="flex gap-1 text-sm whitespace-nowrap">
        @foreach($items as $item)
            @php $isActive = request()->routeIs($item['active']); @endphp
            <li>
                <a href="{{ $accountNavRoute($item['route']) }}"
                   @if($isActive) aria-current="page" @endif
                   class="flex items-center gap-2 px-3 py-3 -mb-px border-b-2 transition
                          {{ $isActive ? 'nav-link-active border-[var(--brand)]' : 'nav-link border-transparent hover:border-border-strong' }}">
                    <x-ui.icon :name="$item['icon']" :size="16" />
                    {{ $item['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
