@extends('website.layouts.app')

@section('title', 'شارژ کیف پول')

@section('content')
@php
    // مقدار فعلی فیلد: ورودی ردشده‌ی قبلی (old) ← مبلغ پیشنهادی انتخاب‌شده ← خالی
    $current = old('amount', $prefill !== null ? \App\Support\Money::toMajorString($prefill) : null);

    $walletRoute = fn (string $name) => $store->isReseller()
        ? route('website.store.'.$name, $store->reseller->slug)
        : route('website.'.$name);

    // دکمه‌های پیشنهادی لینک GET به همین صفحه‌اند (بدون JS؛ سازگار با CSP) و محصولِ بازگشت را حفظ می‌کنند.
    $presetUrl = fn (int $minor) => url()->current().'?'.http_build_query(array_filter([
        'amount' => \App\Support\Money::toMajorString($minor),
        'product' => $returnProduct,
    ]));
@endphp
    <div class="max-w-xl">
        <x-ui.page-header title="شارژ کیف پول" subtitle="پس از شارژ، موجودی شما برای خرید و تمدید قابل‌استفاده است">
            <x-slot:actions>
                <x-ui.button :href="$walletRoute('wallet.show')" variant="ghost" size="sm">بازگشت به کیف‌پول</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card>
            <div class="flex items-center justify-between text-sm">
                <span class="text-muted">موجودی فعلی</span>
                <span class="font-bold tabular">{{ \App\Support\Money::format($balance) }}</span>
            </div>

            @error('charge')
                <x-ui.alert type="danger" class="mt-4">{{ $message }}</x-ui.alert>
            @enderror

            @if($methods->isEmpty())
                <x-ui.alert type="info" class="mt-4">در حال حاضر هیچ روش پرداختی فعال نیست.</x-ui.alert>
            @else
                <form method="POST"
                      action="{{ $store->isReseller() ? route('website.store.wallet.charge.store', $store->reseller->slug) : route('website.wallet.charge.store') }}"
                      class="mt-6 space-y-5">
                    @csrf
                    @if(! empty($returnProduct))
                        {{-- D-3: بعد از شارژ به Checkout همین محصول برگرد (خرید خودکار ممنوع). --}}
                        <input type="hidden" name="return_product" value="{{ (int) $returnProduct }}">
                    @endif

                    <div>
                        <x-ui.field name="amount" type="number" inputmode="numeric" required
                                    :label="'مبلغ ('.\App\Support\Money::label().')'"
                                    :value="$current"
                                    :min="\App\Support\Money::toMajorString($minimum)"
                                    :step="\App\Support\Money::inputStep()"
                                    :hint="'حداقل مبلغ شارژ '.\App\Support\Money::format($minimum).' است.'" />

                        @if(count($presets) > 0)
                            <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="مبلغ‌های پیشنهادی">
                                @foreach($presets as $preset)
                                    <x-ui.button :href="$presetUrl($preset)"
                                                 :variant="$prefill === $preset ? 'primary' : 'secondary'"
                                                 size="sm"
                                                 :aria-current="$prefill === $preset ? 'true' : null">
                                        {{ \App\Support\Money::format($preset) }}
                                    </x-ui.button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <fieldset>
                        <legend class="label">روش پرداخت</legend>
                        @error('payment_method_id')<p class="field-error">{{ $message }}</p>@enderror
                        <div class="space-y-2">
                            @foreach($methods as $method)
                                <label class="flex items-start gap-3 rounded-md border border-border px-3 py-3 text-sm cursor-pointer hover:bg-surface-2">
                                    <input type="radio" name="payment_method_id" value="{{ $method->id }}" required
                                           @checked((int) old('payment_method_id', $methods->count() === 1 ? $method->id : 0) === $method->id)
                                           class="mt-1">
                                    <span>
                                        <span class="block font-medium">{{ $method->name }}</span>
                                        <span class="block text-xs text-muted mt-0.5">
                                            {{ $method->type === 'card_to_card'
                                                ? 'واریز به کارت و ثبت رسید؛ پس از تأیید، کیف‌پول شارژ می‌شود.'
                                                : 'پرداخت آنلاین؛ شما به درگاه منتقل می‌شوید.' }}
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-ui.button type="submit" block>ادامه</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
@endsection
