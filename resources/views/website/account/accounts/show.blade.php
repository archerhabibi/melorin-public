@extends('website.layouts.app')

@section('title', 'جزئیات اکانت')

@section('content')
@php
    $account = $overview->account;
    $quote = $overview->renewal;
    $state = $overview->stateKey();
    $showUsage = in_array($state, ['active', 'expiring'], true);
@endphp

    @include('website.account._nav', ['crumbs' => [['label' => 'جزئیات اکانت']]])

    <x-ui.page-header :title="$account->product->name ?? 'اکانت VPN'" :subtitle="'شناسه‌ی سرویس: '.$account->id">
        <x-slot:actions>
            @include('website.account.accounts._state', ['state' => $state])
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('renewal_success'))
        @php($result = session('renewal_success'))
        <x-ui.alert type="success" class="mb-4">
            <div class="whitespace-pre-line">✅ اکانت شما با موفقیت تمدید شد.

موجودی کیف پول قبل از تمدید: {{ \App\Support\Money::format($result['balance_before']) }}
موجودی کیف پول بعد از تمدید: {{ \App\Support\Money::format($result['balance_after']) }}</div>
        </x-ui.alert>
    @endif

    @if(session('renewal_error'))
        <x-ui.alert type="danger" class="mb-4"><div class="whitespace-pre-line">{{ session('renewal_error') }}</div></x-ui.alert>
    @endif

    @if(session('usage_notice'))
        @php($notice = session('usage_notice'))
        <x-ui.alert :type="$notice['tone']" class="mb-4">{{ $notice['message'] }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            {{-- مصرف --}}
            <section class="card" aria-labelledby="svc-usage">
                <div class="flex items-center justify-between gap-2 mb-4">
                    <h2 id="svc-usage" class="text-sm font-medium text-muted">مصرف حجم</h2>
                    @if($showUsage)
                        <form method="POST" action="{{ $url('accounts.usage.refresh', [$account->id]) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">به‌روزرسانی مصرف</x-ui.button>
                        </form>
                    @endif
                </div>

                @if($showUsage)
                    @include('website.account.accounts._usage', ['account' => $account])
                    <p class="mt-3 text-xs text-muted">
                        @if($overview->usageSyncedAt)
                            آخرین به‌روزرسانی مصرف: {{ $overview->usageSyncedAt->format('Y/m/d H:i') }}
                        @else
                            مصرف هنوز از سرور دریافت نشده است.
                        @endif
                    </p>
                    @if($overview->trafficTone === 'danger')
                        <p class="mt-2 text-sm text-danger">حجم این سرویس تمام شده است؛ با تمدید، حجم از نو اعمال می‌شود.</p>
                    @elseif($overview->trafficTone === 'warning')
                        <p class="mt-2 text-sm text-warning">حجم سرویس رو به پایان است.</p>
                    @endif
                @else
                    <p class="text-sm text-muted">برای سرویسِ منقضی یا غیرفعال، مصرف لحظه‌ای نمایش داده نمی‌شود.</p>
                @endif
            </section>

            {{-- مشخصات --}}
            <section class="card" aria-labelledby="svc-info">
                <h2 id="svc-info" class="text-sm font-medium text-muted mb-3">مشخصات سرویس</h2>
                <dl class="space-y-2 text-sm text-muted">
                    <div class="flex justify-between gap-2">
                        <dt>تاریخ انقضا</dt>
                        <dd>{{ $account->expires_at?->format('Y/m/d H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>زمان باقی‌مانده</dt>
                        <dd>
                            @if($overview->isLapsed)
                                منقضی‌شده
                            @elseif($overview->remainingDays === null)
                                نامحدود
                            @else
                                {{ $overview->remainingDays === 0 ? 'امروز' : $overview->remainingDays.' روز' }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>حجم کل</dt>
                        <dd>{{ $overview->isUnlimitedTraffic() ? 'نامحدود' : number_format((float) $account->traffic_gb, 1).' گیگابایت' }}</dd>
                    </div>
                </dl>
            </section>

            {{--
                طبق تصمیم این کنترلر: فقط subscription_url نمایش داده می‌شود، نه config_data خام.
                اگر پنل این محصول subscription_url نداشته باشد، پیام راهنما نشان داده می‌شود — نه خطا.
            --}}
            <section class="card" aria-labelledby="svc-connect">
                <h2 id="svc-connect" class="text-sm font-medium text-muted mb-3">اطلاعات اتصال</h2>

                @if($account->subscription_url)
                    <div class="flex items-center gap-2">
                        <input id="subscription-url" type="text" readonly value="{{ $account->subscription_url }}"
                               class="input flex-1 text-xs bg-surface-2 text-muted" dir="ltr">
                        <button type="button" data-copy="#subscription-url" class="btn btn-primary">کپی</button>
                    </div>
                @else
                    <p class="text-sm text-subtle">لینک اتصال هنوز آماده نیست.</p>
                @endif
            </section>
        </div>

        {{-- تمدید --}}
        <aside>
            <section class="card" aria-labelledby="svc-renew">
                <h2 id="svc-renew" class="text-sm font-medium text-muted mb-3">تمدید سرویس</h2>

                @if($quote->product)
                    <dl class="space-y-2 text-sm text-muted">
                        <div class="flex justify-between gap-2">
                            <dt>تعرفه</dt>
                            <dd>{{ $quote->product->name }}</dd>
                        </div>
                        @if($quote->price > 0 || $quote->canRenew)
                            <div class="flex justify-between gap-2">
                                <dt>هزینه‌ی تمدید</dt>
                                <dd class="font-medium text-text tabular">{{ $quote->isFree() ? 'رایگان' : \App\Support\Money::format($quote->price) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt>موجودی کیف‌پول</dt>
                                <dd class="tabular">{{ \App\Support\Money::format($overview->renewal->balance) }}</dd>
                            </div>
                        @endif
                    </dl>
                @endif

                @if($quote->canRenew)
                    <form method="POST" action="{{ $url('accounts.renew', [$account->id]) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="idempotency_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <x-ui.button type="submit" block>
                            تمدید اکانت{{ $quote->isFree() ? '' : ' — '.\App\Support\Money::format($quote->price) }}
                        </x-ui.button>
                    </form>
                    <p class="mt-3 text-xs text-muted">
                        مدت و حجم همین تعرفه از نو اعمال می‌شود؛ روزهای باقی‌مانده از دست نمی‌رود و مصرف حجم صفر می‌شود.
                    </p>
                @else
                    <x-ui.alert :type="$quote->needsTopUp() ? 'warning' : 'info'" class="mt-4">
                        {{ $quote->message }}
                        @if($quote->needsTopUp())
                            <div class="mt-1 text-xs">کمبود موجودی: {{ \App\Support\Money::format($quote->shortfall()) }}</div>
                        @endif
                    </x-ui.alert>
                    @if($quote->needsTopUp())
                        <x-ui.button :href="$url('wallet.charge.show')" block icon="wallet" class="mt-3">شارژ کیف‌پول</x-ui.button>
                    @endif
                @endif
            </section>
        </aside>
    </div>
@endsection
