@extends('website.layouts.app')

@section('title', 'تأیید ایمیل')

@section('content')
    <div class="max-w-md mx-auto bg-surface border rounded-lg p-6">
        <h1 class="text-lg font-bold mb-4">ایمیل خود را تأیید کنید</h1>

        <p class="text-sm text-muted">
            لینک تأیید به <span class="font-medium">{{ auth()->user()->email }}</span> ارسال شد.
            تا تأیید ایمیل، فقط «خرید» و «شارژ کیف‌پول» غیرفعال است؛ بقیه‌ی سایت در دسترس است.
        </p>

        @if(session('status'))
            <div class="mt-4 rounded border border-success/30 bg-success-soft text-success px-3 py-2 text-sm">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
            @csrf
            <button type="submit" class="bg-brand px-4 py-2 rounded text-on-brand text-sm font-medium">
                ارسال مجدد لینک تأیید
            </button>
        </form>
    </div>
@endsection
