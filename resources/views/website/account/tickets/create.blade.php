@extends('website.layouts.app')

@section('title', 'تیکت جدید')

@section('content')
    @php $crumbs = [['label' => 'تیکت جدید']]; @endphp
    @include('website.account._nav')

    <div class="max-w-xl">
        <x-ui.page-header title="تیکت جدید" subtitle="سؤال یا مشکل خود را برای پشتیبانی بنویسید">
            <x-slot:actions>
                <x-ui.button :href="$route('tickets.index')" variant="ghost" size="sm">بازگشت به تیکت‌ها</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card>
            @error('ticket')
                <x-ui.alert type="danger" class="mb-4">{{ $message }}</x-ui.alert>
            @enderror

            @if($counts->active() >= $maxOpen)
                <x-ui.alert type="info">
                    شما {{ $maxOpen }} تیکت باز دارید. برای ثبت تیکت جدید، ابتدا یکی را ببندید یا منتظر پاسخ بمانید.
                </x-ui.alert>
            @else
                <form method="POST" action="{{ $route('tickets.store') }}" class="space-y-5">
                    @csrf

                    <x-ui.field name="subject" label="موضوع" required maxlength="100" minlength="3"
                                hint="مثلاً: مشکل در اتصال سرویس" />

                    <div>
                        <label for="f-message" class="label">متن پیام</label>
                        <textarea id="f-message" name="message" rows="7" required minlength="5" maxlength="4000"
                                  @error('message') aria-invalid="true" aria-describedby="e-message" @enderror
                                  class="input">{{ old('message') }}</textarea>
                        @error('message')<p id="e-message" class="field-error">{{ $message }}</p>@enderror
                        <p class="hint">حداکثر ۴۰۰۰ نویسه. شماره‌ی سفارش یا نام سرویس را بنویسید تا سریع‌تر پیگیری شود.</p>
                    </div>

                    <x-ui.button type="submit" block>ثبت تیکت</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
@endsection
