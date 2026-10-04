@extends('website.layouts.app')

@section('title', 'خرید به‌عنوان مهمان')

@section('content')
    {{--
        B4.3 — Guest Checkout UX. ورودی: $item (CatalogItem)، $product، $price، $store، $prefill (GuestCheckout|null)، $route.
        قرارداد داده بدون تغییر است (G2): email تنها فیلد الزامی؛ name/phone اختیاری.
    --}}
    <x-ui.breadcrumb :items="[
        ['label' => 'تعرفه‌ها', 'url' => $route('home')],
        ['label' => $product->name, 'url' => $route('products.show', ['product' => $product->id])],
        ['label' => 'خرید مهمان'],
    ]" />

    <x-ui.steps :items="['اطلاعات', 'ورود یا ثبت‌نام', 'پرداخت']" :current="1" class="max-w-3xl" />

    <div class="grid max-w-3xl gap-6 md:grid-cols-5">
        <x-ui.card class="md:col-span-3">
            <h1 class="page-title">خرید به‌عنوان مهمان</h1>
            <p class="mt-2 text-sm text-muted">
                فقط ایمیل لازم است. پرداخت بعد از ورود یا ثبت‌نام انجام می‌شود و همین خرید از همان‌جا ادامه پیدا می‌کند.
            </p>

            <form method="POST" action="{{ $route('guest-checkout.store', ['product' => $product->id]) }}"
                  class="mt-6 space-y-4">
                @csrf
                <x-ui.field name="guest_email" type="email" label="ایمیل" required autofocus
                            :value="$prefill?->guest_email"
                            autocomplete="email" inputmode="email" dir="ltr"
                            placeholder="name@example.com"
                            hint="برای تأیید حساب و دریافت اطلاعات سرویس استفاده می‌شود." />

                <x-ui.field name="guest_name" label="نام و نام خانوادگی (اختیاری)"
                            :value="$prefill?->guest_name"
                            autocomplete="name" maxlength="100" />

                <x-ui.field name="guest_phone" type="tel" label="شماره تماس (اختیاری)"
                            :value="$prefill?->guest_phone"
                            autocomplete="tel" inputmode="tel" dir="ltr" maxlength="32"
                            placeholder="09123456789" />

                <x-ui.button type="submit" block icon="mail">ادامه و ورود / ثبت‌نام</x-ui.button>
            </form>

            <p class="mt-4 text-center text-sm text-muted">
                قبلاً ثبت‌نام کرده‌اید؟
                <a href="{{ $route('login') }}" class="link">ورود به حساب</a>
            </p>
        </x-ui.card>

        <aside class="md:col-span-2" aria-label="خلاصه‌ی خرید">
            <x-ui.card :flat="true" class="p-5">
                <h2 class="text-sm font-semibold">خلاصه‌ی خرید</h2>
                <p class="mt-3 font-medium">{{ $product->name }}</p>
                <p class="mt-1 text-xs text-muted">{{ $item->category->name }}</p>

                <dl class="mt-4 space-y-2 text-sm text-muted">
                    <div class="flex justify-between"><dt>مدت زمان</dt><dd>{{ $item->durationLabel() }}</dd></div>
                    <div class="flex justify-between"><dt>حجم</dt><dd>{{ $item->trafficLabel() }}</dd></div>
                </dl>

                <div class="divider my-4"></div>

                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">مبلغ</span>
                    <span class="text-lg font-bold text-brand tabular">{{ \App\Support\Money::format($price) }}</span>
                </div>

                @if($item->isLowStock())
                    <x-ui.badge tone="warning" class="mt-3">{{ $item->remaining() }} عدد باقی مانده</x-ui.badge>
                @endif
            </x-ui.card>

            <ul class="mt-4 space-y-2 text-xs text-muted">
                <li class="flex items-start gap-2"><x-ui.icon name="lock" :size="14" class="mt-0.5" /><span>پرداخت فقط بعد از ورود به حساب انجام می‌شود.</span></li>
                <li class="flex items-start gap-2"><x-ui.icon name="info" :size="14" class="mt-0.5" /><span>تا قبل از پرداخت هیچ حسابی ساخته نمی‌شود و هزینه‌ای کسر نمی‌شود.</span></li>
            </ul>
        </aside>
    </div>
@endsection
