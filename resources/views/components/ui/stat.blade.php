@props(['label', 'value', 'icon' => null])
<div {{ $attributes->merge(['class' => 'card']) }}>
    <div class="flex items-center justify-between text-sm text-muted">
        <span>{{ $label }}</span>
        @if($icon)<x-ui.icon :name="$icon" :size="18" />@endif
    </div>
    <div class="mt-2 text-2xl font-bold tabular">{{ $value }}</div>
    @if(trim((string) $slot) !== '')<div class="mt-1 text-xs text-muted">{{ $slot }}</div>@endif
</div>
