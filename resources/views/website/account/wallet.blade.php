@extends('website.layouts.app')

@section('title', 'کیف‌پول')

@section('content')
@php
    // نام route را داخل View هم hardcode نمی‌کنیم؛ همان الگوی _nav (Main یا Reseller).
    $walletRoute = fn (string $name, array $params = []) => $store->isReseller()
        ? route('website.store.'.$name, [$store->reseller->slug, ...$params])
        : route('website.'.$name, $params);

    $money = fn (int $minor) => \App\Support\Money::format($minor);
@endphp

    @include('website.account._nav')

    <x-ui.page-header title="کیف‌پول" subtitle="موجودی، شارژها و گردش حساب شما در این فروشگاه">
        <x-slot:actions>
            <x-ui.button :href="$walletRoute('wallet.charge.show')" size="sm" icon="plus">شارژ کیف‌پول</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- خلاصه --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-ui.stat label="موجودی" :value="$money($overview->balance)" icon="wallet" />
        <x-ui.stat :label="'دریافتی ('.$overview->windowDays.' روز اخیر)'" :value="$money($overview->credited)" icon="plus" />
        <x-ui.stat :label="'پرداختی ('.$overview->windowDays.' روز اخیر)'" :value="$money($overview->debited)" icon="orders" />
        <x-ui.stat label="شارژ در انتظار" :value="number_format($overview->pendingCount)" icon="alert">
            @if($overview->pendingCount > 0)
                مجموع {{ $money($overview->pendingAmount) }}
            @else
                موردی در انتظار نیست
            @endif
        </x-ui.stat>
    </div>

    {{-- شارژهای اخیر --}}
    <section class="mb-6" aria-labelledby="wallet-charges">
        <h2 id="wallet-charges" class="text-sm font-medium text-muted mb-3">شارژهای اخیر</h2>

        @if($charges->isEmpty())
            <x-ui.empty-state icon="wallet" title="هنوز شارژی انجام نداده‌اید">
                برای خرید و تمدید سرویس، کیف‌پول را شارژ کنید.
                <div class="mt-4">
                    <x-ui.button :href="$walletRoute('wallet.charge.show')" size="sm" icon="plus">شارژ کیف‌پول</x-ui.button>
                </div>
            </x-ui.empty-state>
        @else
            <div class="card-flat overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>تاریخ (شمسی / میلادی)</th>
                            <th>مبلغ</th>
                            <th>روش پرداخت</th>
                            <th>وضعیت</th>
                            <th><span class="sr-only">اقدام</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($charges as $charge)
                            @php $receipt = $receiptUrl($charge); @endphp
                            <tr>
                                <td class="text-muted tabular whitespace-nowrap">
                                    <div>{{ \App\Support\JalaliDate::format($charge->createdAt, true) }}</div>
                                    <div class="text-xs" dir="ltr">{{ $charge->createdAt->format('Y-m-d H:i') }}</div>
                                </td>
                                <td class="tabular">{{ \App\Support\Money::number($charge->amount) }}</td>
                                <td>{{ $charge->methodName }}</td>
                                <td><x-ui.badge :tone="$charge->tone()">{{ $charge->label() }}</x-ui.badge></td>
                                <td class="text-end">
                                    @if($receipt)
                                        <a href="{{ $receipt }}" class="link text-xs whitespace-nowrap">ثبت رسید</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- گردش حساب --}}
    <section aria-labelledby="wallet-transactions">
        <h2 id="wallet-transactions" class="text-sm font-medium text-muted mb-3">گردش حساب</h2>

        <form method="GET" action="{{ $walletRoute('wallet.show') }}" class="card mb-4" aria-label="فیلتر گردش حساب">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="f-direction" class="label">جهت</label>
                    <select id="f-direction" name="direction" class="input">
                        <option value="">همه</option>
                        <option value="in" @selected($filter->direction === 'in')>دریافتی (واریز)</option>
                        <option value="out" @selected($filter->direction === 'out')>پرداختی (برداشت)</option>
                    </select>
                </div>
                <div>
                    <label for="f-type" class="label">نوع</label>
                    <select id="f-type" name="type" class="input">
                        <option value="">همه</option>
                        @foreach($typeLabels as $value => $label)
                            <option value="{{ $value }}" @selected($filter->type === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-from" class="label">از تاریخ</label>
                    <input id="f-from" type="date" name="from" value="{{ $filter->from?->format('Y-m-d') }}" class="input">
                    @if($filter->from)<p class="text-xs text-muted mt-1 tabular">{{ \App\Support\JalaliDate::format($filter->from) }} شمسی</p>@endif
                </div>
                <div>
                    <label for="f-to" class="label">تا تاریخ</label>
                    <input id="f-to" type="date" name="to" value="{{ $filter->to?->format('Y-m-d') }}" class="input">
                    @if($filter->to)<p class="text-xs text-muted mt-1 tabular">{{ \App\Support\JalaliDate::format($filter->to) }} شمسی</p>@endif
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2">
                <x-ui.button type="submit" size="sm">اعمال فیلتر</x-ui.button>
                @if($filter->isActive())
                    <x-ui.button :href="$walletRoute('wallet.show')" variant="ghost" size="sm">حذف فیلتر</x-ui.button>
                @endif
            </div>
        </form>

        @if($transactions->isEmpty())
            @if($filter->isActive())
                <x-ui.empty-state icon="info" title="تراکنشی با این فیلتر پیدا نشد">
                    بازه‌ی تاریخ یا نوع تراکنش را تغییر دهید.
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="info" title="هنوز تراکنشی ثبت نشده است">
                    پس از اولین شارژ یا خرید، گردش حساب اینجا نمایش داده می‌شود.
                </x-ui.empty-state>
            @endif
        @else
            <div class="card-flat overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>تاریخ (شمسی / میلادی)</th>
                            <th>نوع</th>
                            <th>مبلغ</th>
                            <th>موجودی پس از تراکنش</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $transaction)
                            @php $description = $transaction->publicDescription(); @endphp
                            <tr>
                                <td class="text-muted tabular whitespace-nowrap">
                                    <div>{{ \App\Support\JalaliDate::format($transaction->created_at, true) }}</div>
                                    <div class="text-xs" dir="ltr">{{ $transaction->created_at->format('Y-m-d H:i') }}</div>
                                </td>
                                <td>
                                    <div>{{ $typeLabels[$transaction->type] ?? $transaction->type }}</div>
                                    @if($description)
                                        <div class="text-xs text-muted mt-0.5">{{ $description }}</div>
                                    @endif
                                    @if($transaction->linked_order_id)
                                        <a href="{{ $orderUrl($transaction->linked_order_id) }}" class="link text-xs">مشاهده سفارش #{{ $transaction->linked_order_id }}</a>
                                    @endif
                                </td>
                                <td class="tabular whitespace-nowrap {{ $transaction->amount >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $transaction->amount >= 0 ? '+' : '' }}{{ \App\Support\Money::number($transaction->amount) }}
                                </td>
                                <td class="text-muted tabular whitespace-nowrap">{{ \App\Support\Money::number($transaction->balance_after) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $transactions->links() }}
            </div>
        @endif
    </section>
@endsection
