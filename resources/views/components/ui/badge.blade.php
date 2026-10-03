@props(['tone' => 'neutral'])
<span {{ $attributes->merge(['class' => "badge badge-{$tone}"]) }}>{{ $slot }}</span>
