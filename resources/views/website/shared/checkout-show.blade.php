@extends('website.layouts.app')

@section('title', 'تایید خرید')

@section('content')
    {{--
        B4.4 — Payment Experience. ورودی: $item (CatalogItem)، $product، $price، $quote (CheckoutQuote)، $topup (مبلغ پیشنهادی
        شارژ یا null)، $canCharge، $fromGuest، $route. قیمت و کمبود از Core است؛ این View هیچ محاسبه‌ای ندارد.
    --}}
    <x-ui.breadcrumb :items="[
        ['label' => 'تعرفه‌ها', 'url' => $route('home')],
        ['label' => $product->name, 'url' => $route('products.show', ['product' => $product->id])],
        ['label' => 'تأیید و پرداخت'],
    ]" />

    @if($fromGuest)
        <x-ui.steps :items="['اطلاعات', 'ورود یا ثبت‌نام', 'پرداخت']" :current="3" class="max-w-3xl" />
    @endif

    <div class="grid max-w-3xl gap-6 md:grid-cols-5">
        <x-ui.card class="md:col-span-3">
            <h1 class="page-title">تأیید و پرداخت</h1>
            <p class="mt-2 text-sm text-muted">پرداخت از موجودی کیف‌پول شما انجام می‌شود و اکانت بلافاصله ساخته می‌شود.</p>

            @error('checkout')
                <x-ui.alert type="danger" class="mt-4">{{ $message }}</x-ui.alert>
            @enderror

            @if($item->isSoldOut())
                <x-ui.alert type="warning" class="mt-4">ظرفیت فروش این تعرفه تکمیل شده است.</x-ui.alert>
                <x-ui.button class="mt-6" variant="secondary" :href="$route('products.show', ['product' => $product->id])">مشاهده‌ی تعرفه‌های جایگزین</x-ui.button>
            @elseif(! $quote->isAffordable())
                {{-- B4.4: به‌جای «موجودی کافی نیست»، دقیقاً بگو چقدر کم است و با یک کلیک همان مبلغ را شارژ کن. --}}
                <x-ui.alert type="warning" class="mt-4">
                    موجودی کیف‌پول شما برای این خرید
                    <strong class="tabular">{{ \App\Support\Money::format($quote->shortfall()) }}</strong>
                    کم است.
                </x-ui.alert>

                @if($canCharge)
                    <x-ui.button class="mt-6" block icon="wallet"
                        :href="$route('wallet.charge.show', ['product' => $product->id, 'amount' => \App\Support\Money::toMajorString($topup)])">
                        شارژ {{ \App\Support\Money::format($topup) }} و بازگشت به خرید
                    </x-ui.button>
                    <p class="mt-3 text-xs text-muted">
                        بعد از شارژ دوباره به همین صفحه برمی‌گردید؛ خرید خودکار انجام نمی‌شود و آخرین تأیید با شماست.
                    </p>
                @else
                    <x-ui.alert type="info" class="mt-4">در حال حاضر هیچ روش پرداختی برای شارژ فعال نیست؛ لطفاً با پشتیبانی تماس بگیرید.</x-ui.alert>
                @endif
            @else
                {{-- فعلاً فقط پرداخت از کیف‌پول. data-submit-lock: دکمه بعد از ارسال قفل می‌شود (علاوه بر توکن Idempotency). --}}
                <form method="POST" action="{{ $route('checkout.store', ['product' => $product->id]) }}" class="mt-6" data-submit-lock>
                    @csrf
                    <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
                    <x-ui.button type="submit" block icon="check" data-busy-text="در حال ثبت خرید…">
                        پرداخت {{ \App\Support\Money::format($price) }} از کیف‌پول
                    </x-ui.button>
                </form>
                <p class="mt-3 text-xs text-muted">با زدن دکمه، مبلغ از کیف‌پول کسر و اکانت ساخته می‌شود. دوبار کلیک‌کردن خرید دوم نمی‌سازد.</p>
            @endif
        </x-ui.card>

        <aside class="md:col-span-2" aria-label="خلاصه‌ی سفارش">
            <x-ui.card :flat="true" class="p-5">
                <h2 class="text-sm font-semibold">خلاصه‌ی سفارش</h2>
                <p class="mt-3 font-medium">{{ $product->name }}</p>
                <p class="mt-1 text-xs text-muted">{{ $item->category->name }} · {{ $item->durationLabel() }} · {{ $item->trafficLabel() }}</p>

                <div class="divider my-4"></div>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">مبلغ</dt><dd class="font-bold tabular">{{ \App\Support\Money::format($price) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">موجودی کیف‌پول</dt><dd class="tabular">{{ \App\Support\Money::format($balance) }}</dd></div>
                    @if($quote->isAffordable())
                        <div class="flex justify-between"><dt class="text-muted">موجودی پس از خرید</dt><dd class="tabular text-success">{{ \App\Support\Money::format($quote->balanceAfter()) }}</dd></div>
                    @else
                        <div class="flex justify-between"><dt class="text-muted">کمبود</dt><dd class="tabular text-danger">{{ \App\Support\Money::format($quote->shortfall()) }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>
        </aside>
    </div>
@endsection
