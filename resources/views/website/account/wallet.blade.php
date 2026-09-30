@extends('website.layouts.app')

@section('title', 'کیف‌پول')

@section('content')
    @include('website.account._nav')

    <div class="bg-white border rounded-lg p-6 mb-6 flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-500">موجودی کیف‌پول</p>
            <p class="text-2xl font-bold mt-1">{{ number_format($balance) }} تومان</p>
        </div>

        {{--
            طبق فاز W4 بند ۱: «Charge با همان مسیرهای Payment فاز W2» —
            این دکمه فقط لینک به ChargeController موجود است، نه یک فرم
            شارژ جدید.
        --}}
        <a href="{{ $store->isReseller() ? route('website.store.wallet.charge.show', $store->reseller->slug) : route('website.wallet.charge.show') }}"
           class="px-4 py-2 rounded text-white" style="background: var(--brand)">
            شارژ کیف‌پول
        </a>
    </div>

    <div class="bg-white border rounded-lg overflow-hidden">
        <h2 class="px-6 py-3 border-b font-medium text-sm text-gray-600">گردش حساب</h2>

        @if($transactions->isEmpty())
            <p class="px-6 py-8 text-center text-gray-400 text-sm">هنوز تراکنشی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
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
                            <td class="px-6 py-3 text-gray-500">{{ $transaction->created_at->format('Y/m/d H:i') }}</td>
                            <td class="px-6 py-3">{{ \App\Models\WalletTransaction::typeLabels()[$transaction->type] ?? $transaction->type }}</td>
                            <td class="px-6 py-3 {{ $transaction->amount >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                {{ $transaction->amount >= 0 ? '+' : '' }}{{ number_format($transaction->amount) }}
                            </td>
                            <td class="px-6 py-3 text-gray-500">{{ number_format($transaction->balance_after) }}</td>
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
