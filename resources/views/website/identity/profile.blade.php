@extends('website.layouts.app')

@section('title', 'پروفایل')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">پروفایل</h1>
        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>نام</dt><dd>{{ $user->full_name }}</dd></div>
            <div class="flex justify-between"><dt>ایمیل</dt><dd>{{ $user->email }}</dd></div>
        </dl>
    </div>

    {{--
        اتصال Telegram (Master G10) — ویجت رسمی تلگرام؛ TelegramLinkController
        امضا را واقعاً با bot_token تأیید می‌کند. فقط Main Context.
    --}}
    @if($telegramBotUsername)
        <div class="mt-4 bg-white border rounded-lg p-6 max-w-xl">
            <h2 class="text-base font-bold">اتصال تلگرام</h2>

            @error('telegram')
                <div class="mt-3 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
            @enderror

            @if($user->telegram_id)
                <p class="mt-3 text-sm text-green-700">حساب تلگرام شما متصل است.</p>
            @else
                <p class="mt-2 text-sm text-gray-500">
                    با اتصال تلگرام، هم می‌توانید سریع‌تر وارد شوید و هم پیام‌های سفارش را در ربات دریافت کنید.
                </p>
                <div class="mt-4">
                    <script async src="https://telegram.org/js/telegram-widget.js?22"
                            data-telegram-login="{{ $telegramBotUsername }}"
                            data-size="large"
                            data-auth-url="{{ route('website.identity.telegram.callback') }}?state={{ $telegramLinkState }}"
                            data-request-access="write"></script>
                </div>
            @endif
        </div>
    @endif
@endsection
