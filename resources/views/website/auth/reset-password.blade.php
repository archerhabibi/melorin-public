@extends('website.layouts.app')

@section('title', 'تعیین رمز عبور جدید')

@section('content')
    <div class="max-w-sm mx-auto bg-surface border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-6">تعیین رمز عبور جدید</h1>

        @if($errors->any())
            <div class="mb-4 rounded border border-danger/30 bg-danger-soft text-danger px-3 py-2 text-sm">
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
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">رمز عبور جدید</label>
                <input type="password" name="password" required
                       class="input">
            </div>
            <div>
                <label class="block text-sm mb-1">تکرار رمز عبور جدید</label>
                <input type="password" name="password_confirmation" required
                       class="input">
            </div>
            <button type="submit" class="bg-brand w-full py-2 rounded text-on-brand">تغییر رمز عبور</button>
        </form>
    </div>
@endsection
