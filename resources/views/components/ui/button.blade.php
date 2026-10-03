@props(['variant' => 'primary', 'size' => null, 'block' => false, 'href' => null, 'type' => 'button', 'icon' => null])
@php
    $cls = trim('btn btn-'.$variant.($size ? ' btn-'.$size : '').($block ? ' btn-block' : ''));
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if($icon)<x-ui.icon :name="$icon" :size="16" />@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if($icon)<x-ui.icon :name="$icon" :size="16" />@endif{{ $slot }}
    </button>
@endif
