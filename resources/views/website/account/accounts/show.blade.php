@extends('website.layouts.app')

@section('title', 'جزئیات اکانت')

@section('content')
    @include('website.account._nav')

    <div class="bg-white border rounded-lg p-6 max-w-xl">
        <h1 class="text-xl font-bold">{{ $account->product->name ?? 'اکانت VPN' }}</h1>

        @if(session('renewal_success'))
            @php($result = session('renewal_success'))
            <div class="mt-4 p-3 rounded bg-green-50 text-green-800 text-sm whitespace-pre-line">
                ✅ اکانت شما با موفقیت تمدید شد.

                موجودی کیف پول قبل از تمدید: {{ \App\Support\Money::format($result['balance_before']) }}
                موجودی کیف پول بعد از تمدید: {{ \App\Support\Money::format($result['balance_after']) }}
            </div>
        @endif

        @if(session('renewal_error'))
            <div class="mt-4 p-3 rounded bg-red-50 text-red-800 text-sm whitespace-pre-line">
                {{ session('renewal_error') }}
            </div>
        @endif

        <dl class="mt-4 space-y-2 text-sm text-gray-600">
            <div class="flex justify-between">
                <dt>وضعیت</dt>
                <dd class="font-medium {{ $account->isExpired() ? 'text-red-600' : 'text-green-700' }}">
                    {{ $account->isExpired() ? 'منقضی‌شده' : 'فعال' }}
                </dd>
            </div>
            <div class="flex justify-between">
                <dt>تاریخ انقضا</dt>
                <dd>{{ $account->expires_at?->format('Y/m/d H:i') ?? '—' }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>حجم باقی‌مانده</dt>
                @php($remaining = $account->remainingTrafficGb())
                <dd>{{ $remaining === null ? 'نامحدود' : number_format($remaining, 1).' گیگابایت' }}</dd>
            </div>
        </dl>

        {{--
            تمدید با همان قیمت/مدت/حجم تعرفه‌ی فعلی —
            دقیقاً هم‌رفتار با AccountsHandler::renew() ربات تلگرام.
            دروازه‌ی واقعی سمت RenewalService/PurchaseGuard است؛ این
            دکمه فقط UX است (بند ۵۰).
        --}}
        <form method="POST"
              action="{{ $store->isReseller() ? route('website.store.accounts.renew', [$store->reseller->slug, $account->id]) : route('website.accounts.renew', $account->id) }}"
              class="mt-6 pt-4 border-t">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ \Illuminate\Support\Str::uuid() }}">
            <button type="submit" class="w-full py-2 rounded text-white" style="background: var(--brand)">
                تمدید اکانت (به قیمت فعلی تعرفه)
            </button>
        </form>

        {{--
            طبق تصمیم این کنترلر: فقط subscription_url نمایش داده
            می‌شود، نه config_data خام. اگر پنل این محصول
            subscription_url نداشته باشد، پیام راهنما نشان داده
            می‌شود — نه خطا.
        --}}
        <div class="mt-6 pt-4 border-t">
            <p class="text-sm font-medium text-gray-700 mb-2">اطلاعات اتصال</p>

            @if($account->subscription_url)
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ $account->subscription_url }}"
                           class="flex-1 text-xs border rounded px-3 py-2 bg-gray-50 text-gray-600" dir="ltr">
                    <button type="button"
                            x-data
                            @click="navigator.clipboard.writeText('{{ $account->subscription_url }}')"
                            class="px-3 py-2 rounded text-white text-sm" style="background: var(--brand)">
                        کپی
                    </button>
                </div>
            @else
                <p class="text-sm text-gray-400">لینک اتصال هنوز آماده نیست.</p>
            @endif
        </div>
    </div>
@endsection
