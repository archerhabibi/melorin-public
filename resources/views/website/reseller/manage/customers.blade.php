@extends('website.layouts.app')

@section('title', 'مشتریان فروشگاه')

@section('content')
    <h1 class="text-xl font-bold">مشتریان فروشگاه {{ $reseller->getFilamentName() }}</h1>
    <p class="text-sm text-gray-500 mt-1">فقط مشاهده — موجودی هر کیف‌پول مخصوص همین فروشگاه است، نه کیف‌پول اصلی مشتری.</p>

    <div class="mt-6 bg-white border rounded-lg overflow-hidden">
        <table class="w-full text-sm text-right">
            <thead class="bg-gray-50 text-gray-500 border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">نام</th>
                    <th class="px-4 py-2 font-medium">شماره تماس</th>
                    <th class="px-4 py-2 font-medium">موجودی کیف‌پول در این فروشگاه</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($customers as $customer)
                    <tr>
                        <td class="px-4 py-3">{{ $customer->full_name ?: '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $customer->phone ?: '—' }}</td>
                        <td class="px-4 py-3 font-medium">{{ \App\Support\Money::format($customer->websiteWalletBalance) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-gray-400">هنوز مشتری‌ای ثبت نشده است.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
@endsection
