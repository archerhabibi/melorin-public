@extends('website.layouts.app')

@section('title', 'ثبت‌نام')

@section('content')
    <div class="max-w-sm mx-auto bg-surface border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-6">ساخت حساب کاربری</h1>

        @if($errors->any())
            <div class="mb-4 rounded border border-danger/30 bg-danger-soft text-danger px-3 py-2 text-sm">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.register.store', $storeContext->reseller->slug) : route('website.register.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">نام و نام خانوادگی</label>
                <input type="text" name="full_name" value="{{ old('full_name', $guestPrefill['name'] ?? '') }}" required autofocus
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">ایمیل</label>
                <input type="email" name="email" value="{{ old('email', $guestPrefill['email'] ?? '') }}" required
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">شماره تماس (اختیاری)</label>
                <input type="text" name="phone" value="{{ old('phone', $guestPrefill['phone'] ?? '') }}"
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">رمز عبور</label>
                <input type="password" name="password" required
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">تکرار رمز عبور</label>
                <input type="password" name="password_confirmation" required
                       class="input">
            </div>
            <button type="submit" class="bg-brand w-full py-2 rounded text-on-brand">ثبت‌نام</button>
        </form>

        <div class="mt-4 text-sm text-center">
            <a href="{{ $storeContext->isReseller() ? route('website.store.login', $storeContext->reseller->slug) : route('website.login') }}" class="text-muted hover:text-text">حساب دارید؟ وارد شوید</a>
        </div>
    </div>
@endsection
