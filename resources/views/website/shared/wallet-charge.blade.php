@extends('website.layouts.app')

@section('title', 'شارژ کیف پول')

@section('content')
    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">شارژ کیف پول</h1>

        @error('charge')
            <div class="mt-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{{ $message }}</div>
        @enderror

        @if($methods->isEmpty())
            <div class="mt-4 rounded border border-gray-200 bg-gray-50 text-gray-600 px-4 py-3 text-sm">
                در حال حاضر هیچ روش پرداختی فعال نیست.
            </div>
        @else
            <form method="POST"
                  action="{{ $store->isReseller() ? route('website.store.wallet.charge.store', $store->reseller->slug) : route('website.wallet.charge.store') }}"
                  class="mt-6 space-y-4">
                @csrf
                @if(! empty($returnProduct))
                    {{-- D-3: بعد از شارژ به Checkout همین محصول برگرد (خرید خودکار ممنوع). --}}
                    <input type="hidden" name="return_product" value="{{ (int) $returnProduct }}">
                @endif
                <div>
                    <label class="block text-sm text-gray-600 mb-1">مبلغ ({{ \App\Support\Money::label() }})</label>
                    <input type="number" name="amount" min="{{ \App\Support\Money::toMajorString(\App\Support\Money::minTopup()) }}" step="{{ \App\Support\Money::inputStep() }}" value="{{ old('amount') }}" required
                           class="w-full border rounded px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-sm text-gray-600 mb-2">روش پرداخت</label>
                    <div class="space-y-2">
                        @foreach($methods as $method)
                            <label class="flex items-center gap-2 border rounded px-3 py-2 text-sm cursor-pointer">
                                <input type="radio" name="payment_method_id" value="{{ $method->id }}" required>
                                {{ $method->name }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="px-4 py-2 rounded text-white text-sm font-medium" style="background: var(--brand)">
                    ادامه
                </button>
            </form>
        @endif
    </div>
@endsection
