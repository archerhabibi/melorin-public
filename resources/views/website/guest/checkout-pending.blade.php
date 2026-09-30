@extends('website.layouts.app')

@section('title', 'خرید مهمان — در انتظار ورود')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">اطلاعات شما ثبت شد</h1>

        <p class="mt-2 text-sm text-gray-500">
            برای تکمیل خرید، وارد حساب خود شوید یا ثبت‌نام کنید؛ همین خرید از همین‌جا ادامه پیدا می‌کند.
            پرداخت فقط پس از ورود انجام می‌شود.
        </p>

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $guestCheckout->product->name }}</dd></div>
            <div class="flex justify-between"><dt>ایمیل</dt><dd>{{ $guestCheckout->guest_email }}</dd></div>
            @if($guestCheckout->guest_name)
                <div class="flex justify-between"><dt>نام</dt><dd>{{ $guestCheckout->guest_name }}</dd></div>
            @endif
            @if($guestCheckout->guest_phone)
                <div class="flex justify-between"><dt>شماره تماس</dt><dd>{{ $guestCheckout->guest_phone }}</dd></div>
            @endif
            <div class="flex justify-between"><dt>معتبر تا</dt><dd>{{ $guestCheckout->expires_at->format('H:i') }}</dd></div>
        </dl>

        <div class="mt-6 flex gap-3">
            <a href="{{ $store->isReseller() ? route('website.store.login', $store->reseller->slug) : route('website.login') }}"
               class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">ورود</a>
            <a href="{{ $store->isReseller() ? route('website.store.register', $store->reseller->slug) : route('website.register') }}"
               class="px-4 py-2 rounded border text-sm font-medium text-gray-700">ثبت‌نام</a>
        </div>

        <p class="mt-3 text-xs text-gray-500">
            ایمیل واردشده به‌تنهایی هویت شما را ثابت نمی‌کند؛ ورود یا ثبت‌نام (با تأیید ایمیل) لازم است.
        </p>
    </div>
@endsection
