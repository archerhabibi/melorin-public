{{--
    Steps (B4.3). <x-ui.steps :items="['اطلاعات', 'ورود یا ثبت‌نام', 'پرداخت']" :current="2" />
    current از ۱ شروع می‌شود (بیرون از بازه clamp می‌شود). مرحله‌ی تمام‌شده ✓ دارد، مرحله‌ی جاری
    aria-current="step" و مراحل بعدی خاکستری؛ وضعیت فقط با رنگ منتقل نمی‌شود (عدد/✓ + متن SR).
--}}
@props(['items', 'current' => 1, 'failed' => null])
@php
    $total = count($items);
    $current = max(1, min($total, (int) $current));
    // B4.5: failed = شماره‌ی (۱-پایه) مرحله‌ی شکست‌خورده؛ با رنگ خطر + «!» + متن SR (نه فقط رنگ).
    $failed = $failed !== null ? max(1, min($total, (int) $failed)) : null;
@endphp
<nav aria-label="مراحل خرید" {{ $attributes->merge(['class' => 'mb-6']) }}>
    <p class="sr-only">مرحله‌ی {{ $failed ?? $current }} از {{ $total }}</p>
    <ol class="flex items-center gap-2 text-xs sm:text-sm">
        @foreach($items as $label)
            @php
                $n = $loop->iteration;
                $isFailed = $failed === $n;
                $done = ! $isFailed && $n < ($failed ?? $current);
                $active = ! $isFailed && $failed === null && $n === $current;
            @endphp
            <li class="flex items-center gap-2 {{ $loop->last ? '' : 'flex-1' }}" @if($active || $isFailed) aria-current="step" @endif>
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold
                    {{ $isFailed ? 'bg-danger-soft text-danger' : ($active ? 'bg-brand text-on-brand' : ($done ? 'bg-success-soft text-success' : 'bg-surface-2 text-muted')) }}">
                    @if($isFailed)!@elseif($done)<x-ui.icon name="check" :size="14" />@else{{ $n }}@endif
                </span>
                <span class="{{ $isFailed ? 'font-semibold text-danger' : ($active ? 'font-semibold text-text' : 'text-muted') }}">{{ $label }}@if($isFailed)<span class="sr-only"> (ناموفق)</span>@endif</span>
                @unless($loop->last)<span class="divider h-px flex-1" aria-hidden="true"></span>@endunless
            </li>
        @endforeach
    </ol>
</nav>
