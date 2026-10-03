@props(['flat' => false])
<div {{ $attributes->merge(['class' => $flat ? 'card-flat' : 'card']) }}>{{ $slot }}</div>
