@extends('website.layouts.app')

@section('title', 'دعوت از دوستان')

@section('content')
    @include('website.account._nav')

    @php
        $registerRoute = $store->isReseller()
            ? route('website.store.register', $store->reseller->slug)
            : route('website.register');
        $referralLink = $registerRoute.'?ref='.$referralCode;
    @endphp

    <div class="bg-white border rounded-lg p-6 mb-6">
        <h1 class="text-lg font-bold mb-2">لینک دعوت اختصاصی شما</h1>

        <div class="flex items-center gap-2 mt-3">
            <input type="text" readonly value="{{ $referralLink }}"
                   class="flex-1 text-xs border rounded px-3 py-2 bg-gray-50 text-gray-600" dir="ltr">
            <button type="button" x-data
                    @click="navigator.clipboard.writeText('{{ $referralLink }}')"
                    class="px-3 py-2 rounded text-white text-sm" style="background: var(--brand)">
                کپی
            </button>
        </div>

        <p class="text-sm text-gray-500 mt-4">تعداد زیرمجموعه‌ها: <strong>{{ $referredCount }}</strong></p>

        @if($bonusAmount > 0)
            <p class="text-sm text-gray-500 mt-1">
                با عضویت هر نفر از طریق این لینک، {{ number_format($bonusAmount) }} تومان به کیف پول شما اضافه می‌شود.
            </p>
        @endif
    </div>

    <div class="bg-white border rounded-lg overflow-hidden">
        <h2 class="px-6 py-3 border-b font-medium text-sm text-gray-600">کمیسیون‌های پرداخت‌شده</h2>

        @if($commissions->isEmpty())
            <p class="px-6 py-8 text-center text-gray-400 text-sm">هنوز کمیسیونی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
                    <tr>
                        <th class="px-6 py-2 text-right">تاریخ</th>
                        <th class="px-6 py-2 text-right">مبلغ</th>
                        <th class="px-6 py-2 text-right">وضعیت</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach($commissions as $commission)
                        <tr>
                            <td class="px-6 py-3 text-gray-500">{{ $commission->created_at->format('Y/m/d') }}</td>
                            <td class="px-6 py-3 text-green-700">+{{ number_format($commission->amount) }}</td>
                            <td class="px-6 py-3 text-gray-500">{{ $commission->status === 'paid' ? 'پرداخت‌شده' : $commission->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-3 border-t">
                {{ $commissions->links() }}
            </div>
        @endif
    </div>
@endsection
