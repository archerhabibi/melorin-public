@extends('website.layouts.app')

@section('title', 'کیف‌پول')

@section('content')
    @include('website.account._nav')

    <div class="bg-surface border rounded-lg p-6 mb-6 flex items-center justify-between">
        <div>
            <p class="text-sm text-muted">موجودی کیف‌پول</p>
            <p class="text-2xl font-bold mt-1">{{ \App\Support\Money::format($balance) }}</p>
        </div>

        {{--
            «Charge با همان مسیرهای Payment» —
            این دکمه فقط لینک به ChargeController موجود است، نه یک فرم
            شارژ جدید.
        --}}
        <a href="{{ $store->isReseller() ? route('website.store.wallet.charge.show', $store->reseller->slug) : route('website.wallet.charge.show') }}"
           class="bg-brand px-4 py-2 rounded text-on-brand">
            شارژ کیف‌پول
        </a>
    </div>

    <div class="bg-surface border rounded-lg overflow-hidden">
        <h2 class="px-6 py-3 border-b font-medium text-sm text-muted">گردش حساب</h2>

        @if($transactions->isEmpty())
            <p class="px-6 py-8 text-center text-subtle text-sm">هنوز تراکنشی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-muted text-xs">
                    <tr>
                        <th class="px-6 py-2 text-right">تاریخ</th>
                        <th class="px-6 py-2 text-right">نوع</th>
                        <th class="px-6 py-2 text-right">مبلغ</th>
                        <th class="px-6 py-2 text-right">موجودی پس از تراکنش</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach($transactions as $transaction)
                        <tr>
                            <td class="px-6 py-3 text-muted">{{ $transaction->created_at->format('Y/m/d H:i') }}</td>
                            <td class="px-6 py-3">{{ \App\Models\WalletTransaction::typeLabels()[$transaction->type] ?? $transaction->type }}</td>
                            <td class="px-6 py-3 {{ $transaction->amount >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $transaction->amount >= 0 ? '+' : '' }}{{ \App\Support\Money::number($transaction->amount) }}
                            </td>
                            <td class="px-6 py-3 text-muted">{{ \App\Support\Money::number($transaction->balance_after) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-3 border-t">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
@endsection
