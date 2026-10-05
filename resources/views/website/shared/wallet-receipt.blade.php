@extends('website.layouts.app')

@section('title', 'ثبت رسید کارت‌به‌کارت')

@section('content')
    {{--
        B4.4 — صفحه‌ی ثبت رسید. ورودی: $payment، $instructions (تنظیمات روش پرداخت)، $returnUrl (فقط Session سرور)، $route.
        مراحل: ۱ واریز ← ۲ ثبت رسید ← ۳ تأیید ادمین. بدون JS: کپی شماره کارت/مبلغ با data-copy-value (کمکی دیزاین‌سیستم).
    --}}
    @php($submitted = (bool) $payment->receipt_image)

    <div class="max-w-xl">
        <x-ui.page-header title="ثبت رسید واریز" subtitle="پس از تأیید ادمین، کیف‌پول شما شارژ می‌شود">
            <x-slot:actions>
                <x-ui.button :href="$route('wallet.show')" variant="ghost" size="sm">بازگشت به کیف‌پول</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.steps :items="['واریز', 'ثبت رسید', 'تأیید ادمین']" :current="$submitted ? 3 : 2" />

        <x-ui.card>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">اطلاعات واریز</h2>
                <x-ui.badge :tone="$submitted ? 'info' : 'warning'">{{ $submitted ? 'در انتظار بررسی' : 'در انتظار ثبت رسید' }}</x-ui.badge>
            </div>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">مبلغ دقیق واریز</dt>
                    <dd class="flex items-center gap-2">
                        <span class="font-bold tabular">{{ \App\Support\Money::format($payment->amount) }}</span>
                        <button type="button" class="link text-xs" data-copy-value="{{ \App\Support\Money::toMajorString($payment->amount) }}" data-copied="کپی شد">کپی مبلغ</button>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted">شماره کارت</dt>
                    <dd class="flex items-center gap-2">
                        <span class="tabular" dir="ltr">{{ $instructions['card_number'] ?? '—' }}</span>
                        @if(! empty($instructions['card_number']))
                            <button type="button" class="link text-xs" data-copy-value="{{ preg_replace('/\D+/', '', (string) $instructions['card_number']) }}" data-copied="کپی شد">کپی</button>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3"><dt class="text-muted">به نام</dt><dd>{{ $instructions['card_holder_name'] ?? '—' }}</dd></div>
            </dl>

            @unless($submitted)
                <x-ui.alert type="info" class="mt-4">
                    همین مبلغ را به کارت بالا واریز کنید، سپس تصویر رسید را همین‌جا ثبت کنید. بدون ثبت رسید، شارژ بررسی نمی‌شود.
                </x-ui.alert>
            @endunless
        </x-ui.card>

        <x-ui.card class="mt-4">
            @if($submitted)
                <x-ui.alert type="success">
                    رسید شما ثبت شد و در انتظار بررسی ادمین است. پس از تأیید، کیف‌پول شما شارژ می‌شود.
                </x-ui.alert>

                <div class="mt-5 flex flex-wrap gap-3">
                    @if(! empty($returnUrl))
                        {{-- D-3: بعد از شارژ، کاربر خودش به همان خرید برمی‌گردد (خرید خودکار ممنوع). --}}
                        <x-ui.button :href="$returnUrl">بازگشت به تکمیل خرید</x-ui.button>
                    @endif
                    <x-ui.button :href="$route('wallet.show')" variant="secondary">وضعیت شارژ در کیف‌پول</x-ui.button>
                </div>
                @if(! empty($returnUrl))
                    <p class="mt-3 text-xs text-muted">تا زمان تأیید ادمین موجودی افزایش نمی‌یابد؛ پس از تأیید می‌توانید خرید را کامل کنید.</p>
                @endif
            @else
                <form method="POST"
                      action="{{ $route('wallet.receipt.store', ['payment' => $payment->id]) }}"
                      enctype="multipart/form-data" class="space-y-4" data-submit-lock>
                    @csrf
                    <x-ui.field name="depositor_name" label="نام و نام خانوادگی صاحب کارت واریزکننده" required
                                autocomplete="name" minlength="2" maxlength="100" />

                    <x-ui.field name="receipt" type="file" label="تصویر رسید" required
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                hint="فرمت‌های JPG، PNG، WebP یا PDF؛ حداکثر ۵ مگابایت." />

                    <x-ui.button type="submit" block icon="check" data-busy-text="در حال ارسال رسید…">ثبت رسید</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
@endsection
