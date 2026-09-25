@extends('website.layouts.app')

@section('title', 'تایید خرید')

@section('content')
    <a href="{{ $store->isReseller() ? route('website.store.products.show', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.products.show', $product->id) }}"
       class="text-sm text-gray-500">&rarr; بازگشت</a>

    <div class="mt-4 bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">تایید خرید</h1>

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><dt>تعرفه</dt><dd>{{ $product->name }}</dd></div>
            <div class="flex justify-between"><dt>مبلغ</dt><dd class="font-bold text-gray-900">{{ number_format($price) }} تومان</dd></div>
            <div class="flex justify-between"><dt>موجودی کیف پول شما</dt><dd>{{ number_format($balance) }} تومان</dd></div>
        </dl>

        @error('checkout')
            <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        @if($balance < $price)
            <div class="mt-4 rounded border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
                موجودی کیف پول شما برای این خرید کافی نیست. افزایش موجودی (Zarinpal/Card-to-Card) در پچ بعدی فعال می‌شود.
            </div>
        @else
            {{-- فعلاً فقط پرداخت از کیف‌پول (W2 بند ۵ — Wallet Payment). --}}
            <form method="POST"
                  action="{{ $store->isReseller() ? route('website.store.checkout.store', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.checkout.store', $product->id) }}"
                  class="mt-6">
                @csrf
                <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
                <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                    پرداخت از کیف پول و تکمیل خرید
                </button>
            </form>
        @endif
    </div>
@endsection
