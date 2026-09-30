@extends('website.layouts.app')

@section('title', 'سفارش‌های من')

@section('content')
    @include('website.account._nav')

    <div class="bg-white border rounded-lg overflow-hidden">
        <h1 class="px-6 py-3 border-b font-medium text-sm text-gray-600">سفارش‌های من</h1>

        @if($orders->isEmpty())
            <p class="px-6 py-8 text-center text-gray-400 text-sm">هنوز سفارشی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
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
                            <td class="px-6 py-3 text-gray-500">#{{ $order->id }}</td>
                            <td class="px-6 py-3">{{ $order->product->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-gray-500">{{ $order->created_at->format('Y/m/d H:i') }}</td>
                            <td class="px-6 py-3 {{ $order->needsAttention() ? 'text-red-600 font-medium' : '' }}">
                                {{ \App\Models\Order::statusLabels()[$order->status] ?? $order->status }}
                            </td>
                            <td class="px-6 py-3">
                                <a href="{{ $store->isReseller() ? route('website.store.orders.show', [$store->reseller->slug, $order->id]) : route('website.orders.show', $order->id) }}"
                                   class="text-sm hover:underline" style="color: var(--brand)">
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
