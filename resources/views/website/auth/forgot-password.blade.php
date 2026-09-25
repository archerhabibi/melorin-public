@extends('website.layouts.app')

@section('title', 'فراموشی رمز عبور')

@section('content')
    <div class="max-w-sm mx-auto bg-white border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-2">بازیابی رمز عبور</h1>
        <p class="text-sm text-gray-500 mb-6">ایمیل خود را وارد کنید تا لینک بازیابی برایتان ارسال شود.</p>

        @if($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ $storeContext->isReseller() ? route('website.store.password.email', $storeContext->reseller->slug) : route('website.password.email') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">ایمیل</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded border-gray-300 focus:border-gray-500 focus:ring-0">
            </div>
            <button type="submit" class="w-full py-2 rounded text-white" style="background: var(--brand)">ارسال لینک بازیابی</button>
        </form>
    </div>
@endsection
