@extends('website.layouts.app')

@section('title', 'سفارش #'.$order->id)

{{-- B4.5: سفارشِ در حال انجام هر ۱۵ ثانیه خودش را تازه می‌کند (meta refresh: بدون JS/inline-script؛ سازگار با CSP). --}}
@if($tracking->inProgress)
    @push('head')
        <meta http-equiv="refresh" content="15">
    @endpush
@endif

@section('content')
    {{--
        B4.5 — Order Tracking. ورودی: $order، $tracking (Core: مراحل/متن/لحن)، $amount، $service (اکانت مرتبطِ هم‌مالک یا null)، $route.
        این View هیچ تصمیمی نمی‌گیرد؛ علت داخلی شکست (failure_reason) و Config/QR عمداً اینجا نیست.
    --}}
    <x-ui.breadcrumb :items="[
        ['label' => 'حساب من', 'url' => $route('dashboard')],
        ['label' => 'سفارش‌ها', 'url' => $route('orders.index')],
        ['label' => 'سفارش #'.$order->id],
    ]" />

    <div class="max-w-2xl">
        <x-ui.page-header :title="'سفارش #'.$order->id" :subtitle="$order->product->name">
            <x-slot:actions>
                @if($tracking->isRenewal)<x-ui.badge tone="info">تمدید</x-ui.badge>@endif
                <x-ui.badge :tone="$tracking->tone">{{ $tracking->headline }}</x-ui.badge>
            </x-slot:actions>
        </x-ui.page-header>

        @php($failedStep = collect($tracking->steps)->search(fn ($s) => $s['state'] === 'failed'))
        <x-ui.steps :items="$tracking->labels()" :current="$tracking->currentStep()" :failed="$failedStep === false ? null : $failedStep + 1" />

        <x-ui.alert :type="$tracking->tone === 'neutral' ? 'info' : $tracking->tone" class="mb-4">
            <div class="font-medium">{{ $tracking->headline }}</div>
            <div class="mt-1 text-sm">{{ $tracking->message }}</div>

            @if($tracking->autoRetry)
                <div class="mt-2 text-xs">
                    سامانه به‌صورت خودکار دوباره تلاش می‌کند@if($tracking->nextRetryAt && $tracking->nextRetryAt->getTimestamp() > time())
                        (تلاش بعدی حدود {{ \App\Support\JalaliDate::format(\Illuminate\Support\Carbon::instance($tracking->nextRetryAt), true) }})@endif.
                </div>
            @endif
        </x-ui.alert>

        <x-ui.card>
            <h2 class="text-sm font-semibold">جزئیات سفارش</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-muted">شماره‌ی پیگیری</dt><dd class="tabular">#{{ $order->id }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">تعرفه</dt><dd>{{ $order->product->name }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">وضعیت</dt><dd>{{ \App\Models\Order::statusLabels()[$order->status] ?? $order->status }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">نوع</dt><dd>{{ $tracking->isRenewal ? 'تمدید سرویس' : 'خرید سرویس جدید' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">مبلغ</dt><dd class="font-bold tabular">{{ \App\Support\Money::format($amount) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">روش پرداخت</dt><dd>کیف‌پول</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">زمان ثبت</dt><dd>{{ \App\Support\JalaliDate::format($order->created_at, true) }}</dd></div>
                @if($service && ! $tracking->isRenewal && $tracking->steps[3]['state'] === 'done')
                    <div class="flex justify-between gap-3"><dt class="text-muted">زمان تحویل</dt><dd>{{ \App\Support\JalaliDate::format($service->created_at, true) }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="mt-4 flex flex-wrap gap-3">
            @if($service && $tracking->steps[3]['state'] === 'done')
                <x-ui.button :href="$route('accounts.show', ['account' => $service->id])" icon="server">مشاهده‌ی سرویس و اتصال</x-ui.button>
            @endif

            @if($tracking->inProgress)
                <x-ui.button :href="$route('orders.show', ['order' => $order->id])" variant="secondary" icon="refresh">به‌روزرسانی وضعیت</x-ui.button>
            @endif

            @if($tracking->needsSupport)
                <x-ui.button :href="$route('tickets.create')" icon="ticket">ثبت تیکت برای این سفارش</x-ui.button>
            @endif

            <x-ui.button :href="$route('orders.index')" variant="ghost">همه‌ی سفارش‌ها</x-ui.button>
        </div>

        @if($tracking->needsSupport)
            <p class="mt-3 text-xs text-muted">هنگام ثبت تیکت، شماره‌ی پیگیری <span class="tabular">#{{ $order->id }}</span> را بنویسید تا سریع‌تر رسیدگی شود.</p>
        @elseif($tracking->inProgress)
            <p class="mt-3 text-xs text-muted">این صفحه هر ۱۵ ثانیه خودکار تازه می‌شود.</p>
        @endif
    </div>
@endsection
