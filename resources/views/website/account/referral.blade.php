@extends('website.layouts.app')

@section('title', 'دعوت از دوستان')

@section('content')
    @include('website.account._nav')

    @php
        $registerRoute = $store->isReseller()
            ? route('website.store.register', $store->reseller->slug)
            : route('website.register');
        $referralLink = $registerRoute.'?ref='.$referralCode;
    @endphp

    <div class="bg-surface border rounded-lg p-6 mb-6">
        <h1 class="text-lg font-bold mb-2">لینک دعوت اختصاصی شما</h1>

        <div class="flex items-center gap-2 mt-3">
            <input id="referral-link" type="text" readonly value="{{ $referralLink }}"
                   class="input flex-1 text-xs bg-surface-2 text-muted" dir="ltr">
            <button type="button" data-copy="#referral-link" class="btn btn-primary">کپی</button>
        </div>

        <p class="text-sm text-muted mt-4">تعداد زیرمجموعه‌ها: <strong>{{ $referredCount }}</strong></p>

        @if($bonusAmount > 0)
            <p class="text-sm text-muted mt-1">
                با عضویت هر نفر از طریق این لینک، {{ \App\Support\Money::format($bonusAmount) }} به کیف پول شما اضافه می‌شود.
            </p>
        @endif
    </div>

    <div class="bg-surface border rounded-lg overflow-hidden">
        <h2 class="px-6 py-3 border-b font-medium text-sm text-muted">کمیسیون‌های پرداخت‌شده</h2>

        @if($commissions->isEmpty())
            <p class="px-6 py-8 text-center text-subtle text-sm">هنوز کمیسیونی ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-muted text-xs">
                    <tr>
                        <th class="px-6 py-2 text-right">تاریخ</th>
                        <th class="px-6 py-2 text-right">مبلغ</th>
                        <th class="px-6 py-2 text-right">وضعیت</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach($commissions as $commission)
                        <tr>
                            <td class="px-6 py-3 text-muted">{{ $commission->created_at->format('Y/m/d') }}</td>
                            <td class="px-6 py-3 text-success">+{{ \App\Support\Money::number($commission->amount) }}</td>
                            <td class="px-6 py-3 text-muted">{{ $commission->status === 'paid' ? 'پرداخت‌شده' : $commission->status }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-3 border-t">
                {{ $commissions->links() }}
            </div>
        @endif
    </div>
@endsection
