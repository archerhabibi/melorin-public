@props(['title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-start justify-between gap-3']) }}>
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        @if($subtitle)<p class="page-subtitle">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
</div>
