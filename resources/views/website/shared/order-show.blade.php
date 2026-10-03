@extends('website.layouts.app')

@section('title', 'سفارش #'.$order->id)

@section('content')
    <div class="bg-surface border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">سفارش #{{ $order->id }}</h1>

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $order->product->name }}</dd></div>
            <div class="flex justify-between">
                <dt>وضعیت</dt>
                <dd class="font-medium {{ $order->needsAttention() ? 'text-danger' : 'text-text' }}">
                    {{ \App\Models\Order::statusLabels()[$order->status] ?? $order->status }}
                </dd>
            </div>
        </dl>

        {{--
            Provisioning Status Display از Core: این صفحه
            هیچ محاسبه‌ای انجام نمی‌دهد، فقط ستون account روی همان Order
            را نشان می‌دهد. اطلاعات اتصال (Config/QR) عمداً اینجا نیست —
            آن بخشِ «Accounts» است ، نه Order Display.
        --}}
        @if($order->account)
            <div class="mt-4 rounded border border-success/30 bg-success-soft text-success px-4 py-3 text-sm">
                اکانت شما ساخته شد. جزئیات اتصال در بخش «اکانت‌های من» (به‌زودی) در دسترس خواهد بود.
            </div>
        @elseif($order->needsAttention())
            <div class="mt-4 rounded border border-danger/30 bg-danger-soft text-danger px-4 py-3 text-sm">
                در ساخت اکانت شما مشکلی پیش آمد. اگر مبلغ کسر شده، نگران نباشید — سفارش شما ثبت و پیگیری می‌شود.
            </div>
        @else
            <div class="mt-4 rounded border border-border bg-surface-2 text-muted px-4 py-3 text-sm">
                سفارش شما در حال پردازش است.
            </div>
        @endif

    </div>
@endsection
