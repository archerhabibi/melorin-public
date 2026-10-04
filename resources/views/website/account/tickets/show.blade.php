@extends('website.layouts.app')

@section('title', 'تیکت #'.$ticket->id)

@section('content')
    @php $crumbs = [['label' => 'تیکت #'.$ticket->id]]; @endphp
    @include('website.account._nav')

    <x-ui.page-header :title="$ticket->subject" :subtitle="'تیکت #'.$ticket->id.' · '.$ticket->typeLabel().' · ثبت‌شده '.\App\Support\JalaliDate::format($ticket->created_at, true)">
        <x-slot:actions>
            <x-ui.badge :tone="$ticket->statusTone()">{{ $ticket->statusLabel() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    @error('ticket')
        <x-ui.alert type="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <ol class="space-y-3 mb-6" aria-label="گفتگو">
        @foreach($messages as $message)
            @php $mine = $message->sender_type !== 'admin'; @endphp
            <li @if($loop->last) id="last" @endif class="card {{ $mine ? '' : 'border-[var(--brand)]' }}">
                <div class="flex items-center justify-between gap-2 text-xs text-muted mb-2">
                    <span class="font-medium {{ $mine ? '' : 'text-[var(--brand)]' }}">{{ $message->customerLabel() }}</span>
                    <span class="tabular" title="{{ $message->created_at->format('Y-m-d H:i') }}">{{ \App\Support\JalaliDate::format($message->created_at, true) }}</span>
                </div>
                {{-- Blade escape می‌کند؛ whitespace-pre-line فقط خط‌های جدید را نگه می‌دارد --}}
                <p class="text-sm whitespace-pre-line break-words">{{ $message->message }}</p>
            </li>
        @endforeach
    </ol>

    @if($ticket->isOpen())
        <x-ui.card>
            <form method="POST" action="{{ $route('tickets.reply', ['ticket' => $ticket->id]) }}" class="space-y-4">
                @csrf
                <div>
                    <label for="f-message" class="label">پاسخ شما</label>
                    <textarea id="f-message" name="message" rows="5" required minlength="2" maxlength="{{ $maxLength }}"
                              @error('message') aria-invalid="true" aria-describedby="e-message" @enderror
                              class="input">{{ old('message') }}</textarea>
                    @error('message')<p id="e-message" class="field-error">{{ $message }}</p>@enderror
                </div>
                <x-ui.button type="submit">ارسال پاسخ</x-ui.button>
            </form>

            <form method="POST" action="{{ $route('tickets.close', ['ticket' => $ticket->id]) }}" class="mt-4 pt-4 border-t border-border">
                @csrf
                <p class="text-xs text-muted mb-2">مشکل حل شد؟ تیکت را ببندید. تیکت بسته دیگر پاسخ نمی‌پذیرد.</p>
                <x-ui.button type="submit" variant="ghost" size="sm">بستن تیکت</x-ui.button>
            </form>
        </x-ui.card>
    @else
        <x-ui.alert type="info">
            این تیکت بسته شده است.
            <a href="{{ $route('tickets.create') }}" class="link">ثبت تیکت جدید</a>
        </x-ui.alert>
    @endif
@endsection
