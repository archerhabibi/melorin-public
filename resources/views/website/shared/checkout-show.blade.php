@extends('website.layouts.app')

@section('title', 'تایید خرید')

@section('content')
    <a href="{{ $store->isReseller() ? route('website.store.products.show', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.products.show', $product->id) }}"
       class="text-sm text-muted">&rarr; بازگشت</a>

    <div class="mt-4 bg-surface border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">تایید خرید</h1>

        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $product->name }}</dd></div>
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-text">{{ \App\Support\Money::format($price) }}</dd></div>
            <div class="flex justify-between"><dt>موجودی کیف پول شما</dt><dd>{{ \App\Support\Money::format($balance) }}</dd></div>
        </dl>

        @error('checkout')
            <div class="mt-4 rounded border border-danger/30 bg-danger-soft text-danger px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        @if($balance < $price)
            <div class="mt-4 rounded border border-warning/30 bg-warning-soft text-warning px-4 py-3 text-sm">
                موجودی کیف پول شما برای این خرید کافی نیست.
                <a href="{{ $store->isReseller() ? route('website.store.wallet.charge.show', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.wallet.charge.show', ['product' => $product->id]) }}"
                   class="underline font-medium">شارژ کیف پول</a>
                و سپس به همین صفحه برگردید.
            </div>
        @else
            {{-- فعلاً فقط پرداخت از کیف‌پول (Wallet Payment). --}}
            <form method="POST"
                  action="{{ $store->isReseller() ? route('website.store.checkout.store', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.checkout.store', $product->id) }}"
                  class="mt-6">
                @csrf
                <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
                <button type="submit" class="bg-brand px-4 py-2 rounded text-on-brand text-sm font-medium">
                    پرداخت از کیف پول و تکمیل خرید
                </button>
            </form>
        @endif
    </div>
@endsection
