@extends('website.layouts.app')

@section('title', 'سفارش‌های من')

@section('content')
    @include('website.account._nav')

    {{--
        B4.5 — فهرست سفارش‌ها. ورودی: $orders، $counts (Core: total + گروه‌ها)، $group، $groupLabels، $tracking (سرویس Core)، $route.
        فیلتر با لینک GET (بدون JS؛ سازگار با CSP). وضعیت هر ردیف از Core می‌آید.
    --}}
    <x-ui.page-header title="سفارش‌های من" subtitle="پیگیری خرید و تمدیدها" />

    <nav class="flex flex-wrap gap-2 mb-4 text-sm" aria-label="فیلتر وضعیت سفارش">
        @php
            $tabs = [[null, 'همه', $counts['total']]];
            foreach ($groupLabels as $key => $label) { $tabs[] = [$key, $label, $counts[$key]]; }
        @endphp
        @foreach($tabs as [$value, $label, $count])
            <a href="{{ $value ? $route('orders.index').'?group='.$value : $route('orders.index') }}"
               @if($group === $value) aria-current="true" @endif
               class="btn btn-sm {{ $group === $value ? 'btn-primary' : 'btn-secondary' }}">
                {{ $label }} <span class="tabular">({{ number_format($count) }})</span>
            </a>
        @endforeach
    </nav>

    @if($orders->isEmpty())
        @if($group)
            <x-ui.empty-state icon="orders" title="سفارشی با این وضعیت ندارید">
                <div class="mt-4"><x-ui.button :href="$route('orders.index')" variant="ghost" size="sm">نمایش همه</x-ui.button></div>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state icon="orders" title="هنوز سفارشی ثبت نشده است">
                بعد از اولین خرید، وضعیت آن را از همین‌جا پیگیری می‌کنید.
                <div class="mt-4"><x-ui.button :href="$route('home')" size="sm">مشاهده‌ی تعرفه‌ها</x-ui.button></div>
            </x-ui.empty-state>
        @endif
    @else
        <ul class="space-y-3">
            @foreach($orders as $order)
                @php($t = $tracking->track($order))
                <li class="card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <a href="{{ $route('orders.show', ['order' => $order->id]) }}" class="font-medium link break-words">{{ $order->product->name ?? '—' }}</a>
                            <div class="mt-1 text-xs text-muted tabular">
                                #{{ $order->id }} · {{ \App\Support\JalaliDate::format($order->created_at, true) }} ·
                                {{ \App\Support\Money::format($tracking->amount($order)) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($t->isRenewal)<x-ui.badge tone="info">تمدید</x-ui.badge>@endif
                            <x-ui.badge :tone="$t->tone">{{ $t->headline }}</x-ui.badge>
                        </div>
                    </div>

                    @if($t->needsSupport)
                        <p class="mt-2 text-xs text-danger">پرداخت انجام شده ولی سرویس کامل نشده؛ جزئیات و راه پیگیری در صفحه‌ی سفارش است.</p>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
@endsection
