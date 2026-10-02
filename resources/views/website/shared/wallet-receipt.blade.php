@extends('website.layouts.app')

@section('title', 'ثبت رسید کارت‌به‌کارت')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">ثبت رسید واریز</h1>

        @if(session('status'))
            <div class="mt-4 rounded border border-green-200 bg-green-50 text-green-800 px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-gray-900">{{ \App\Support\Money::format($payment->amount) }}</dd></div>
            <div class="flex justify-between"><dt>شماره کارت</dt><dd>{{ $instructions['card_number'] ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt>به نام</dt><dd>{{ $instructions['card_holder_name'] ?? '—' }}</dd></div>
        </dl>

        @if($payment->receipt_image)
            <div class="mt-4 rounded border border-gray-200 bg-gray-50 text-gray-600 px-4 py-3 text-sm">
                رسید شما ثبت شده و در انتظار بررسی ادمین است. پس از تایید، کیف پول شما شارژ خواهد شد.
            </div>
        @else
            @error('receipt')
                <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
            @enderror
            @error('depositor_name')
                <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
            @enderror

            <form method="POST"
                  action="{{ $store->isReseller() ? route('website.store.wallet.receipt.store', ['slug' => $store->reseller->slug, 'payment' => $payment->id]) : route('website.wallet.receipt.store', $payment->id) }}"
                  enctype="multipart/form-data" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm text-gray-600 mb-1">نام و نام خانوادگی صاحب کارت واریزکننده</label>
                    <input type="text" name="depositor_name" required class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">تصویر رسید</label>
                    <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required class="w-full text-sm">
                </div>
                <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                    ثبت رسید
                </button>
            </form>
        @endif
    </div>
@endsection
