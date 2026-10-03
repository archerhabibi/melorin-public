@extends('website.layouts.app')

@section('title', 'داشبورد')

@section('content')
@php
    // نام route را داخل View هم hardcode نمی‌کنیم؛ همان الگوی _nav (Main یا Reseller).
    $dashRoute = fn (string $name, array $params = []) => $store->isReseller()
        ? route('website.store.'.$name, [$store->reseller->slug, ...$params])
        : route('website.'.$name, $params);
@endphp

    @include('website.account._nav')

    <x-ui.page-header title="داشبورد" :subtitle="'سلام '.(auth()->user()->full_name ?: 'کاربر گرامی').'، وضعیت حساب شما در یک نگاه'">
        <x-slot:actions>
            <x-ui.button :href="$dashRoute('wallet.charge.show')" variant="secondary" size="sm" icon="wallet">شارژ کیف‌پول</x-ui.button>
            <x-ui.button :href="$dashRoute('home')" size="sm" icon="plus">خرید سرویس</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- خلاصه --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-ui.stat label="سرویس‌های فعال" :value="number_format($dashboard->activeCount)" icon="server" />
        <x-ui.stat :label="'رو به انقضا (تا '.\App\Services\Core\Customer\CustomerDashboardService::EXPIRING_DAYS.' روز)'"
                   :value="number_format($dashboard->expiringSoonCount)" icon="alert" />
        <x-ui.stat label="منقضی‌شده" :value="number_format($dashboard->lapsedCount)" icon="x" />
        <x-ui.stat label="موجودی کیف‌پول" :value="\App\Support\Money::format($dashboard->walletBalance)" icon="wallet" />
    </div>

    {{-- اعلان‌ها --}}
    @if($dashboard->notices->isNotEmpty())
        <section class="mb-6" aria-labelledby="dash-notices">
            <h2 id="dash-notices" class="text-sm font-medium text-muted mb-3">اعلان‌ها</h2>
            <ul class="space-y-2">
                @foreach($dashboard->notices as $notice)
                    @php $url = $noticeUrl($notice); @endphp
                    <li class="alert alert-{{ $notice->tone }} flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="font-medium">{{ $notice->title }}</div>
                            <div class="text-xs mt-0.5 opacity-90">{{ $notice->body }}</div>
                        </div>
                        @if($url)
                            <a href="{{ $url }}" class="text-xs font-medium underline whitespace-nowrap">مشاهده</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- سرویس‌های فعال --}}
        <section class="lg:col-span-2" aria-labelledby="dash-services">
            <div class="flex items-center justify-between mb-3">
                <h2 id="dash-services" class="text-sm font-medium text-muted">سرویس‌های فعال</h2>
                <a href="{{ $dashRoute('accounts.index') }}" class="link text-xs">همه‌ی اکانت‌ها</a>
            </div>

            @if($dashboard->featuredServices->isEmpty())
                <x-ui.empty-state icon="server" :title="$dashboard->hasServices() ? 'سرویس فعالی ندارید' : 'هنوز سرویسی ندارید'">
                    {{ $dashboard->hasServices() ? 'سرویس‌های منقضی‌شده را از «اکانت‌های من» تمدید کنید یا سرویس جدید بخرید.' : 'از تعرفه‌ها یک سرویس انتخاب کنید.' }}
                    <div class="mt-4">
                        <x-ui.button :href="$dashRoute('home')" size="sm" icon="plus">مشاهده تعرفه‌ها</x-ui.button>
                    </div>
                </x-ui.empty-state>
            @else
                <ul class="space-y-3">
                    @foreach($dashboard->featuredServices as $service)
                        @php
                            $days = $service->remainingDays();
                            $usage = $service->trafficUsagePercent();
                            $remaining = $service->remainingTrafficGb();
                            $expiring = $days !== null && $days <= \App\Services\Core\Customer\CustomerDashboardService::EXPIRING_DAYS;
                            $usageTone = $usage === null ? 'success' : ($usage >= 100 ? 'danger' : ($usage >= \App\Services\Core\Customer\CustomerDashboardService::TRAFFIC_WARN_PERCENT ? 'warning' : 'success'));
                        @endphp
                        <li class="card">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div class="font-medium">{{ $service->product->name ?? 'اکانت VPN' }}</div>
                                    <div class="text-xs text-muted mt-1">
                                        @if($days === null)
                                            بدون تاریخ انقضا
                                        @else
                                            انقضا: {{ $service->expires_at->format('Y/m/d') }}
                                            · {{ $days === 0 ? 'امروز' : $days.' روز مانده' }}
                                        @endif
                                    </div>
                                </div>
                                <x-ui.badge :tone="$expiring ? 'warning' : 'success'">{{ $expiring ? 'رو به انقضا' : 'فعال' }}</x-ui.badge>
                            </div>

                            <div class="mt-4">
                                <div class="flex justify-between text-xs text-muted mb-1.5">
                                    <span>مصرف حجم</span>
                                    <span class="tabular">
                                        @if($usage === null)
                                            نامحدود
                                        @else
                                            {{ $usage }}٪ · {{ number_format($remaining, 1) }} گیگابایت باقی‌مانده
                                        @endif
                                    </span>
                                </div>
                                @if($usage !== null)
                                    <x-ui.progress :value="$usage" :tone="$usageTone" label="حجم مصرف‌شده" />
                                @endif
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <x-ui.button :href="$dashRoute('accounts.show', [$service->id])" variant="secondary" size="sm">مشاهده و تمدید</x-ui.button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- کیف‌پول --}}
        <section aria-labelledby="dash-wallet">
            <div class="flex items-center justify-between mb-3">
                <h2 id="dash-wallet" class="text-sm font-medium text-muted">کیف‌پول</h2>
                <a href="{{ $dashRoute('wallet.show') }}" class="link text-xs">گردش کامل</a>
            </div>

            <div class="card">
                <div class="text-sm text-muted">موجودی</div>
                <div class="mt-1 text-2xl font-bold tabular">{{ \App\Support\Money::format($dashboard->walletBalance) }}</div>
                <x-ui.button :href="$dashRoute('wallet.charge.show')" size="sm" class="mt-4" block icon="plus">شارژ کیف‌پول</x-ui.button>

                <div class="divider my-4"></div>

                @if($dashboard->recentTransactions->isEmpty())
                    <p class="text-sm text-subtle text-center py-2">هنوز تراکنشی ثبت نشده است.</p>
                @else
                    <ul class="space-y-3">
                        @foreach($dashboard->recentTransactions as $tx)
                            <li class="flex items-center justify-between gap-3 text-sm">
                                <div>
                                    <div>{{ \App\Models\WalletTransaction::typeLabels()[$tx->type] ?? $tx->type }}</div>
                                    <div class="text-xs text-muted">{{ $tx->created_at->format('Y/m/d H:i') }}</div>
                                </div>
                                <div class="tabular {{ $tx->amount >= 0 ? 'text-success' : 'text-danger' }}" dir="ltr">
                                    {{ $tx->amount >= 0 ? '+' : '' }}{{ \App\Support\Money::number($tx->amount) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>
@endsection
