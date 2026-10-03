@extends('website.layouts.app')

@section('title', 'سفارش‌های من')

@section('content')
    @include('website.account._nav')

    <div class="bg-surface border rounded-lg overflow-hidden">
        <h1 class="px-6 py-3 border-b font-medium text-sm text-muted">سفارش‌های من</h1>

        @if($orders->isEmpty())
            <p class="px-6 py-8 text-center text-subtle text-sm">هنوز سفارشی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-muted text-xs">
                    <tr>
                        <th class="px-6 py-2 text-right">شماره</th>
                        <th class="px-6 py-2 text-right">تعرفه</th>
                        <th class="px-6 py-2 text-right">تاریخ</th>
                        <th class="px-6 py-2 text-right">وضعیت</th>
                        <th class="px-6 py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach($orders as $order)
                        <tr>
                            <td class="px-6 py-3 text-muted">#{{ $order->id }}</td>
                            <td class="px-6 py-3">{{ $order->product->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-muted">{{ $order->created_at->format('Y/m/d H:i') }}</td>
                            <td class="px-6 py-3 {{ $order->needsAttention() ? 'text-danger font-medium' : '' }}">
                                {{ \App\Models\Order::statusLabels()[$order->status] ?? $order->status }}
                            </td>
                            <td class="px-6 py-3">
                                <a href="{{ $store->isReseller() ? route('website.store.orders.show', [$store->reseller->slug, $order->id]) : route('website.orders.show', $order->id) }}"
                                   class="text-brand text-sm hover:underline">
                                    مشاهده
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-3 border-t">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
@endsection
