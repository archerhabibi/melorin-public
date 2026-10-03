@props(['icon' => 'info', 'title'])
<div {{ $attributes->merge(['class' => 'card text-center py-10']) }}>
    <x-ui.icon :name="$icon" :size="32" class="mx-auto text-subtle" />
    <h2 class="mt-3 font-medium">{{ $title }}</h2>
    @if(trim((string) $slot) !== '')<div class="mt-1 text-sm text-muted">{{ $slot }}</div>@endif
</div>
