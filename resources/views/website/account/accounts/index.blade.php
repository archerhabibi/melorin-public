@extends('website.layouts.app')

@section('title', 'اکانت‌های من')

@section('content')
    @include('website.account._nav')

    <x-ui.page-header title="اکانت‌های من" subtitle="وضعیت، مصرف حجم و تمدید سرویس‌های شما">
        <x-slot:actions>
            <x-ui.button :href="$url('home')" size="sm" icon="plus">خرید سرویس</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($accounts->isEmpty())
        <x-ui.empty-state icon="server" title="هنوز اکانتی برای شما ساخته نشده است">
            از تعرفه‌ها یک سرویس انتخاب کنید.
            <div class="mt-4">
                <x-ui.button :href="$url('home')" size="sm" icon="plus">مشاهده تعرفه‌ها</x-ui.button>
            </div>
        </x-ui.empty-state>
    @else
        <ul class="space-y-3">
            @foreach($accounts as $account)
                @php
                    $state = $account->displayState();
                    $days = $account->remainingDays();
                    // سرویسِ منقضی/غیرفعال نوار مصرف زنده ندارد؛ فقط تمدید/جزئیات
                    $showUsage = in_array($state, ['active', 'expiring'], true);
                @endphp
                <li class="card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <div class="font-medium">{{ $account->product->name ?? 'اکانت VPN' }}</div>
                            <div class="text-xs text-muted mt-1">
                                @if($account->expires_at === null)
                                    بدون تاریخ انقضا
                                @else
                                    انقضا: {{ $account->expires_at->format('Y/m/d') }}
                                    @if($state !== 'expired' && $days !== null)
                                        · {{ $days === 0 ? 'امروز' : $days.' روز مانده' }}
                                    @endif
                                @endif
                            </div>
                        </div>
                        @include('website.account.accounts._state', ['state' => $state])
                    </div>

                    @if($showUsage)
                        <div class="mt-4">@include('website.account.accounts._usage', ['account' => $account])</div>
                    @endif

                    <div class="mt-4 flex items-center gap-2">
                        <x-ui.button :href="$url('accounts.show', [$account->id])" variant="secondary" size="sm">
                            {{ $state === 'expired' ? 'مشاهده و تمدید' : 'مشاهده' }}
                        </x-ui.button>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $accounts->links() }}</div>
    @endif
@endsection
