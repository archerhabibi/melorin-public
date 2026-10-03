@extends('website.layouts.app')

@section('title', 'خرید مهمان — در انتظار ورود')

@section('content')
    @php
        $loginUrl = $store->isReseller() ? route('website.store.login', $store->reseller->slug) : route('website.login');
        $registerUrl = $store->isReseller() ? route('website.store.register', $store->reseller->slug) : route('website.register');
        $cancelUrl = $store->isReseller() ? route('website.store.guest-checkout.cancel', $store->reseller->slug) : route('website.guest-checkout.cancel');
        $minutesLeft = $guestCheckout->minutesLeft();
    @endphp

    <x-ui.card class="max-w-xl">
        <h1 class="page-title">اطلاعات شما ثبت شد</h1>

        <p class="mt-2 text-sm text-muted">
            برای تکمیل خرید، وارد حساب خود شوید یا ثبت‌نام کنید؛ همین خرید از همین‌جا ادامه پیدا می‌کند.
            پرداخت فقط پس از ورود انجام می‌شود.
        </p>

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $product->name }}</dd></div>
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-text">{{ \App\Support\Money::format($price) }}</dd></div>
            <div class="flex justify-between"><dt>ایمیل</dt><dd>{{ $guestCheckout->guest_email }}</dd></div>
            @if($guestCheckout->guest_name)
                <div class="flex justify-between"><dt>نام</dt><dd>{{ $guestCheckout->guest_name }}</dd></div>
            @endif
            @if($guestCheckout->guest_phone)
                <div class="flex justify-between"><dt>شماره تماس</dt><dd>{{ $guestCheckout->guest_phone }}</dd></div>
            @endif
            <div class="flex justify-between">
                <dt>معتبر تا</dt>
                <dd>{{ $guestCheckout->expires_at->format('H:i') }} <span class="text-xs">({{ $minutesLeft }} دقیقه‌ی دیگر)</span></dd>
            </div>
        </dl>

        <div class="mt-6 flex gap-3">
            <x-ui.button :href="$loginUrl">ورود</x-ui.button>
            <x-ui.button :href="$registerUrl" variant="secondary">ثبت‌نام</x-ui.button>
        </div>

        @include('website.auth.partials.google-button')

        <x-ui.alert type="info" class="mt-5">
            ایمیل واردشده به‌تنهایی هویت شما را ثابت نمی‌کند؛ ورود یا ثبت‌نام (با تأیید ایمیل) لازم است.
        </x-ui.alert>

        <form method="POST" action="{{ $cancelUrl }}" class="mt-4">
            @csrf
            <button type="submit" class="link text-sm">لغو و شروع دوباره</button>
        </form>
    </x-ui.card>
@endsection
