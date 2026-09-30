@extends('website.layouts.app')

@section('title', 'خرید به‌عنوان مهمان')

@section('content')
    <a href="{{ $store->isReseller() ? route('website.store.products.show', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.products.show', $product->id) }}"
       class="text-sm text-gray-500">&rarr; بازگشت</a>

    <div class="mt-4 bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">خرید به‌عنوان مهمان</h1>
        <p class="mt-2 text-sm text-gray-500">
            فقط ایمیل لازم است. پرداخت بعد از ورود یا ثبت‌نام انجام می‌شود و همین خرید ادامه پیدا می‌کند.
        </p>

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $product->name }}</dd></div>
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-gray-900">{{ number_format($price) }} تومان</dd></div>
        </dl>

        @error('guest_email') <div class="mt-4 text-sm text-red-700">{{ $message }}</div> @enderror
        @error('guest_name') <div class="mt-2 text-sm text-red-700">{{ $message }}</div> @enderror
        @error('guest_phone') <div class="mt-2 text-sm text-red-700">{{ $message }}</div> @enderror

        <form method="POST"
              action="{{ $store->isReseller() ? route('website.store.guest-checkout.store', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.guest-checkout.store', $product->id) }}"
              class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">ایمیل</label>
                <input type="email" name="guest_email" value="{{ old('guest_email') }}" required class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">نام و نام خانوادگی (اختیاری)</label>
                <input type="text" name="guest_name" value="{{ old('guest_name') }}" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">شماره تماس (اختیاری)</label>
                <input type="text" name="guest_phone" value="{{ old('guest_phone') }}" class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                ادامه
            </button>
        </form>
    </div>
@endsection
