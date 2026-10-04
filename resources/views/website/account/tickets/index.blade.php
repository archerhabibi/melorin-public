@extends('website.layouts.app')

@section('title', 'پشتیبانی')

@section('content')
    @include('website.account._nav')

    <x-ui.page-header title="پشتیبانی" subtitle="تیکت‌های شما و پاسخ‌های تیم پشتیبانی">
        <x-slot:actions>
            <x-ui.button :href="$route('tickets.create')" size="sm" icon="plus">تیکت جدید</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($counts->answered > 0)
        <x-ui.alert type="info" class="mb-4">
            {{ $counts->answered === 1 ? 'یک تیکت شما' : $counts->answered.' تیکت شما' }} پاسخ جدید دارد.
        </x-ui.alert>
    @endif

    {{-- فیلتر وضعیت: لینک GET (بدون JS؛ سازگار با CSP) --}}
    <nav class="flex flex-wrap gap-2 mb-4 text-sm" aria-label="فیلتر وضعیت تیکت">
        @php
            $tabs = [
                [null, 'همه', $counts->total()],
                ['answered', $statusLabels['answered'], $counts->answered],
                ['open', $statusLabels['open'], $counts->open],
                ['closed', $statusLabels['closed'], $counts->closed],
            ];
        @endphp
        @foreach($tabs as [$value, $label, $count])
            <a href="{{ $value ? $route('tickets.index').'?status='.$value : $route('tickets.index') }}"
               @if($status === $value) aria-current="true" @endif
               class="btn btn-sm {{ $status === $value ? 'btn-primary' : 'btn-secondary' }}">
                {{ $label }} <span class="tabular">({{ number_format($count) }})</span>
            </a>
        @endforeach
    </nav>

    @if($tickets->isEmpty())
        @if($status)
            <x-ui.empty-state icon="ticket" title="تیکتی با این وضعیت ندارید">
                <div class="mt-4"><x-ui.button :href="$route('tickets.index')" variant="ghost" size="sm">نمایش همه</x-ui.button></div>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state icon="ticket" title="هنوز تیکتی ثبت نکرده‌اید">
                برای هر سؤال یا مشکل، یک تیکت ثبت کنید تا پشتیبانی پاسخ دهد.
                <div class="mt-4"><x-ui.button :href="$route('tickets.create')" size="sm" icon="plus">ثبت اولین تیکت</x-ui.button></div>
            </x-ui.empty-state>
        @endif
    @else
        <ul class="space-y-3">
            @foreach($tickets as $ticket)
                <li class="card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <a href="{{ $route('tickets.show', ['ticket' => $ticket->id]) }}" class="font-medium link break-words">{{ $ticket->subject }}</a>
                            <div class="text-xs text-muted mt-1 tabular">
                                #{{ $ticket->id }} · {{ $ticket->typeLabel() }} · {{ number_format($ticket->messages_count) }} پیام ·
                                آخرین فعالیت {{ \App\Support\JalaliDate::format($ticket->updated_at, true) }}
                            </div>
                        </div>
                        <x-ui.badge :tone="$ticket->statusTone()">{{ $ticket->statusLabel() }}</x-ui.badge>
                    </div>
                    @if($ticket->latestMessage)
                        <p class="text-sm text-muted mt-3 break-words">
                            <span class="font-medium">{{ $ticket->latestMessage->customerLabel() }}:</span>
                            {{ \Illuminate\Support\Str::limit($ticket->latestMessage->message, 140) }}
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
@endsection
