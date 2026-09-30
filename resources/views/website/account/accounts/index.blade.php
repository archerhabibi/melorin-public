@extends('website.layouts.app')

@section('title', 'اکانت‌های من')

@section('content')
    @include('website.account._nav')

    <div class="bg-white border rounded-lg overflow-hidden">
        <h1 class="px-6 py-3 border-b font-medium text-sm text-gray-600">اکانت‌های من</h1>

        @if($accounts->isEmpty())
            <p class="px-6 py-8 text-center text-gray-400 text-sm">هنوز اکانتی برای شما ساخته نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs">
                    <tr>
                        <th class="px-6 py-2 text-right">تعرفه</th>
                        <th class="px-6 py-2 text-right">انقضا</th>
                        <th class="px-6 py-2 text-right">حجم باقی‌مانده</th>
                        <th class="px-6 py-2 text-right">وضعیت</th>
                        <th class="px-6 py-2 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach($accounts as $account)
                        <tr>
                            <td class="px-6 py-3">{{ $account->product->name ?? '—' }}</td>
                            <td class="px-6 py-3 {{ $account->isExpired() ? 'text-red-600' : 'text-gray-500' }}">
                                {{ $account->expires_at?->format('Y/m/d') ?? '—' }}
                            </td>
                            <td class="px-6 py-3 text-gray-500">
                                @php($remaining = $account->remainingTrafficGb())
                                {{ $remaining === null ? 'نامحدود' : number_format($remaining, 1).' گیگابایت' }}
                            </td>
                            <td class="px-6 py-3">
                                @if($account->isExpired())
                                    <span class="text-red-600">منقضی‌شده</span>
                                @else
                                    <span class="text-green-700">فعال</span>
                                @endif
                            </td>
                            <td class="px-6 py-3">
                                <a href="{{ $store->isReseller() ? route('website.store.accounts.show', [$store->reseller->slug, $account->id]) : route('website.accounts.show', $account->id) }}"
                                   class="text-sm hover:underline" style="color: var(--brand)">
                                    مشاهده
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-3 border-t">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>
@endsection
