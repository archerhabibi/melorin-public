@extends('website.layouts.app')

@section('title', 'تکمیل حساب')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">تکمیل حساب</h1>
        <p class="mt-2 text-sm text-gray-500">
            خرید شما با شماره {{ $user->phone }} ثبت شد. با تنظیم رمز عبور، دفعه‌ی بعد بدون خرید جدید هم می‌توانید وارد شوید.
        </p>

        @error('password')
            <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        <form method="POST"
              action="{{ $store->isReseller() ? route('website.store.identity.complete-profile.store', $store->reseller->slug) : route('website.identity.complete-profile.store') }}"
              class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-600 mb-1">رمز عبور جدید</label>
                <input type="password" name="password" required class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm text-gray-600 mb-1">تکرار رمز عبور</label>
                <input type="password" name="password_confirmation" required class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                ذخیره
            </button>
        </form>

        <a href="{{ $store->isReseller() ? route('website.store.home', $store->reseller->slug) : route('website.home') }}" class="mt-4 inline-block text-sm text-gray-500">فعلاً نه، بعداً</a>
    </div>

    {{--
        فاز W3 بند ۵ (نیمه‌ی دوم، پچ 3.2.5) — Telegram-linking.
        ویجت رسمی تلگرام (نه یک دکمه‌ی دست‌ساز): طبق مستندات
        https://core.telegram.org/widgets/login، با data-auth-url
        مرورگر را مستقیم با query params امضاشده به همان Route هدایت
        می‌کند؛ TelegramLinkController::callback امضا را واقعاً با
        bot_token تایید می‌کند (نه یک HMAC جعلی مثل نمونه‌ی مرجع).
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
