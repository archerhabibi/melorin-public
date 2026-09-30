@extends('website.layouts.app')

@section('title', 'خرید مهمان — در انتظار پرداخت')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">اطلاعات شما ثبت شد</h1>

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $guestCheckout->product->name }}</dd></div>
            <div class="flex justify-between"><dt>نام</dt><dd>{{ $guestCheckout->guest_name }}</dd></div>
            <div class="flex justify-between"><dt>شماره تماس</dt><dd>{{ $guestCheckout->guest_phone }}</dd></div>
            <div class="flex justify-between"><dt>معتبر تا</dt><dd>{{ $guestCheckout->expires_at->format('H:i') }}</dd></div>
        </dl>

        {{--
            پچ 3.2.4 — بند ۳ فاز W3: دکمه‌ی زیر مستقیماً وارد همان
            مسیر تست‌شده‌ی Checkout (پچ ۳.۲.۱/۳.۲.۲) می‌شود — جزئیات
            تصمیم در docs/PHASE-W3-PART2-GUEST-PURCHASE.md.
        --}}
        @error('purchase')
            <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        <form method="POST"
              action="{{ $store->isReseller() ? route('website.store.guest-checkout.purchase', $store->reseller->slug) : route('website.guest-checkout.purchase') }}"
              class="mt-6">
            @csrf
            <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                تکمیل پرداخت
            </button>
        </form>

        <p class="mt-3 text-xs text-gray-500">
            اگر از قبل حساب دارید،
            <a href="{{ $store->isReseller() ? route('website.store.login', $store->reseller->slug) : route('website.login') }}" class="underline">وارد شوید</a>
            تا خرید از حساب فعلی‌تان ثبت شود.
        </p>
    </div>
@endsection
