@extends('website.layouts.app')

@section('title', 'تأیید ایمیل')

@section('content')
    <div class="max-w-md mx-auto bg-white border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-4">ایمیل خود را تأیید کنید</h1>

        <p class="text-sm text-gray-600">
            لینک تأیید به <span class="font-medium">{{ auth()->user()->email }}</span> ارسال شد.
            تا تأیید ایمیل، فقط «خرید» و «شارژ کیف‌پول» غیرفعال است؛ بقیه‌ی سایت در دسترس است.
        </p>

        @if(session('status'))
            <div class="mt-4 rounded border border-green-200 bg-green-50 text-green-800 px-3 py-2 text-sm">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
            @csrf
            <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                ارسال مجدد لینک تأیید
            </button>
        </form>
    </div>
@endsection
