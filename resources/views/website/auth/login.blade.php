@extends('website.layouts.app')

@section('title', 'ورود')

@section('content')
    <x-ui.card class="max-w-sm mx-auto">
        <h1 class="page-title mb-6">ورود به حساب کاربری</h1>

        <x-ui.errors class="mb-4" />

        <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.login.store', $storeContext->reseller->slug) : route('website.login.store') }}" class="space-y-4">
            @csrf
            <x-ui.field name="email" type="email" label="ایمیل" required autofocus autocomplete="username" />
            <x-ui.field name="password" type="password" label="رمز عبور" required autocomplete="current-password" />
            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="remember" class="check">
                مرا به‌خاطر بسپار
            </label>
            <x-ui.button type="submit" block>ورود</x-ui.button>
        </form>

        @include('website.auth.partials.google-button')

        <div class="mt-4 flex justify-between text-sm">
            <a href="{{ $storeContext->isReseller() ? route('website.store.password.request', $storeContext->reseller->slug) : route('website.password.request') }}" class="link">فراموشی رمز عبور</a>
            <a href="{{ $storeContext->isReseller() ? route('website.store.register', $storeContext->reseller->slug) : route('website.register') }}" class="link">ساخت حساب جدید</a>
        </div>
    </x-ui.card>
@endsection
