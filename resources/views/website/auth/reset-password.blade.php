@extends('website.layouts.app')

@section('title', 'تعیین رمز عبور جدید')

@section('content')
    <div class="max-w-sm mx-auto bg-white border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-6">تعیین رمز عبور جدید</h1>

        @if($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.password.store', $storeContext->reseller->slug) : route('website.password.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label class="block text-sm mb-1">ایمیل</label>
                <input type="email" name="email" value="{{ old('email', request('email')) }}" required autofocus
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-sm mb-1">رمز عبور جدید</label>
                <input type="password" name="password" required
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <div>
                <label class="block text-sm mb-1">تکرار رمز عبور جدید</label>
                <input type="password" name="password_confirmation" required
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <button type="submit" class="w-full py-2 rounded text-white" style="background: var(--brand)">تغییر رمز عبور</button>
        </form>
    </div>
@endsection
