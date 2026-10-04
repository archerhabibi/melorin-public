@extends('website.layouts.app')

@section('title', 'خرید مهمان — در انتظار ورود')

@section('content')
    @php
        $loginUrl = $route('login');
        $registerUrl = $route('register');
        $cancelUrl = $route('guest-checkout.cancel');
        $editUrl = $route('guest-checkout.show', ['product' => $product->id]);
        $minutesLeft = $guestCheckout->minutesLeft();
    @endphp

    <x-ui.steps :items="['اطلاعات', 'ورود یا ثبت‌نام', 'پرداخت']" :current="2" class="max-w-xl" />

    <x-ui.card class="max-w-xl">
        <h1 class="page-title">اطلاعات شما ثبت شد</h1>

        @if($soldOut)
            {{-- B4.3: ظرفیت بعد از شروع نشست تمام شده؛ ادامه‌ی ورود فقط به بن‌بست می‌رسد. --}}
            <x-ui.alert type="warning" class="mt-4">
                ظرفیت فروش این تعرفه در همین فاصله تکمیل شد. می‌توانید یکی از تعرفه‌های دیگر را انتخاب کنید.
            </x-ui.alert>
        @else
            <p class="mt-2 text-sm text-muted">
                برای تکمیل خرید، وارد حساب خود شوید یا ثبت‌نام کنید؛ همین خرید از همین‌جا ادامه پیدا می‌کند.
                پرداخت فقط پس از ورود انجام می‌شود.
            </p>
        @endif

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $product->name }}</dd></div>
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-text">{{ \App\Support\Money::format($price) }}</dd></div>
            <div class="flex justify-between"><dt>ایمیل</dt><dd dir="ltr">{{ $guestCheckout->guest_email }}</dd></div>
            @if($guestCheckout->guest_name)
                <div class="flex justify-between"><dt>نام</dt><dd>{{ $guestCheckout->guest_name }}</dd></div>
            @endif
            @if($guestCheckout->guest_phone)
                <div class="flex justify-between"><dt>شماره تماس</dt><dd dir="ltr">{{ $guestCheckout->guest_phone }}</dd></div>
            @endif
            <div class="flex justify-between">
                <dt>معتبر تا</dt>
                <dd>{{ $guestCheckout->expires_at->format('H:i') }} <span class="text-xs">({{ $minutesLeft }} دقیقه‌ی دیگر)</span></dd>
            </div>
        </dl>

        @unless($soldOut)
            <div class="mt-6 flex flex-wrap gap-3">
                <x-ui.button :href="$loginUrl" icon="login">ورود</x-ui.button>
                <x-ui.button :href="$registerUrl" variant="secondary" icon="plus">ثبت‌نام</x-ui.button>
            </div>

            @include('website.auth.partials.google-button')

            <x-ui.alert type="info" class="mt-5">
                ایمیل واردشده به‌تنهایی هویت شما را ثابت نمی‌کند؛ ورود یا ثبت‌نام (با تأیید ایمیل) لازم است.
            </x-ui.alert>
        @else
            <div class="mt-6">
                <x-ui.button :href="$route('products.show', ['product' => $product->id])">مشاهده‌ی تعرفه‌های جایگزین</x-ui.button>
            </div>
        @endunless

        <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            @unless($soldOut)
                <a href="{{ $editUrl }}" class="link">ویرایش اطلاعات</a>
            @endunless
            <form method="POST" action="{{ $cancelUrl }}">
                @csrf
                <button type="submit" class="link">لغو و شروع دوباره</button>
            </form>
        </div>
    </x-ui.card>
@endsection
