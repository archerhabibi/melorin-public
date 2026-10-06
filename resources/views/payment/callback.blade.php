<!DOCTYPE html>
<html lang="fa" dir="rtl">
{{--
    B4.4 — نتیجه‌ی پرداخت آنلاین. صفحه‌ی مستقل (بدون Layout/StoreContext چون بدون ورود و بدون Session فروشگاه است)
    ولی با دیزاین‌سیستم و تم Light/Dark. ورودی‌ها: $success، $message، $amount (اختیاری)، و فقط برای نتیجه‌ی واقعی
    $walletUrl/$retryUrl/$homeUrl. خطاها (بدون این لینک‌ها) فقط پیام عمومی نشان می‌دهند.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نتیجه پرداخت@isset($brand) — {{ $brand->name }}@endisset</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="{{ asset('js/theme-init.js') }}"></script>
    {{-- B5.7: رنگ فروشگاه مبدأ (فقط وقتی پرداخت از مسیر واقعی آمده؛ خطاهای عمومی بدون Payment برند ندارند) --}}
    @isset($brand)
        @include('website.partials.brand-head', ['brand' => $brand])
    @endisset
</head>
<body class="min-h-screen flex items-center justify-center px-4 py-8">
    <main class="w-full max-w-md" role="main">
        <x-ui.card class="text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full {{ $success ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' }}">
                <x-ui.icon :name="$success ? 'check' : 'alert'" :size="28" />
            </div>

            <h1 class="mt-4 text-lg font-bold">{{ $success ? 'پرداخت موفق' : 'پرداخت ناموفق' }}</h1>
            <p class="mt-2 text-sm text-muted leading-7">{{ $message }}</p>

            @isset($amount)
                <div class="mt-4 rounded-md bg-surface-2 px-4 py-3 text-sm">
                    <span class="text-muted">مبلغ:</span>
                    <span class="font-bold tabular">{{ \App\Support\Money::format((int) $amount) }}</span>
                </div>
            @endisset

            {{--
                D-3 / Master 7.2 (شکاف C13): بعد از شارژ موفق، کاربر خودش به Checkout برمی‌گردد؛ خرید به‌صورت
                خودکار انجام نمی‌شود. آدرس فقط از Session سمت سرور می‌آید (ChargeController) و بیرون از سایت نیست.
            --}}
            <div class="mt-6 flex flex-col gap-3">
                @if($success && session('charge_return_checkout_url'))
                    <x-ui.button :href="session('charge_return_checkout_url')" block>بازگشت به تکمیل خرید</x-ui.button>
                @endif

                @isset($walletUrl)
                    @if($success)
                        <x-ui.button :href="$walletUrl" :variant="session('charge_return_checkout_url') ? 'secondary' : 'primary'" block icon="wallet">مشاهده‌ی کیف‌پول</x-ui.button>
                    @else
                        <x-ui.button :href="$retryUrl" block icon="refresh">تلاش دوباره برای شارژ</x-ui.button>
                        <x-ui.button :href="$walletUrl" variant="secondary" block>بازگشت به کیف‌پول</x-ui.button>
                    @endif
                @endisset
            </div>

            @if($success)
                <p class="mt-4 text-xs text-subtle">اگر از ربات تلگرام آمده‌اید، می‌توانید به همان گفتگو برگردید.</p>
            @else
                <p class="mt-4 text-xs text-subtle">اگر مبلغ از حساب شما کسر شده، معمولاً تا چند ساعت برگشت می‌خورد؛ در غیر این صورت با پشتیبانی تماس بگیرید.</p>
            @endif
        </x-ui.card>
    </main>
</body>
</html>
