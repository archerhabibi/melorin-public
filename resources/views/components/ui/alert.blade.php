@props(['type' => 'info'])
@php $icon = ['success' => 'check', 'warning' => 'alert', 'danger' => 'alert', 'info' => 'info'][$type] ?? 'info'; @endphp
<div role="{{ in_array($type, ['danger', 'warning']) ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => "alert alert-{$type} flex items-start gap-2"]) }}>
    <x-ui.icon :name="$icon" :size="18" class="mt-0.5" />
    <div class="min-w-0">{{ $slot }}</div>
</div>
