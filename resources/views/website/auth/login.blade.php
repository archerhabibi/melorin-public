@extends('website.layouts.app')

@section('title', 'ورود')

@section('content')
    <div class="max-w-sm mx-auto bg-white border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-6">ورود به حساب کاربری</h1>

        @if($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.login.store', $storeContext->reseller->slug) : route('website.login.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">ایمیل</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-sm mb-1">رمز عبور</label>
                <input type="password" name="password" required
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember">
                مرا به‌خاطر بسپار
            </label>
            <button type="submit" class="w-full py-2 rounded text-white" style="background: var(--brand)">ورود</button>
        </form>

        <div class="mt-4 flex justify-between text-sm">
            <a href="{{ $storeContext->isReseller() ? route('website.store.password.request', $storeContext->reseller->slug) : route('website.password.request') }}" class="text-gray-500 hover:text-gray-900">فراموشی رمز عبور</a>
            <a href="{{ $storeContext->isReseller() ? route('website.store.register', $storeContext->reseller->slug) : route('website.register') }}" class="text-gray-500 hover:text-gray-900">ساخت حساب جدید</a>
        </div>
    </div>
@endsection
