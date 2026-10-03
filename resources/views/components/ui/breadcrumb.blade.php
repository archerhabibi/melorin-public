{{--
    Breadcrumb (B1.3). <x-ui.breadcrumb :items="[['label' => 'خانه', 'url' => '/'], ['label' => 'کیف‌پول']]" />
    آیتم آخر (بدون url) صفحه‌ی جاری است. در RTL شورون برعکس می‌شود.
--}}
@props(['items'])
@if(count($items) > 1)
    <nav aria-label="مسیر صفحه" {{ $attributes->merge(['class' => 'mb-3 text-xs text-muted']) }}>
        <ol class="flex flex-wrap items-center gap-1">
            @foreach($items as $item)
                @php $isLast = $loop->last || empty($item['url']); @endphp
                <li class="flex items-center gap-1">
                    @if($isLast)
                        <span @if($loop->last) aria-current="page" @endif class="text-text">{{ $item['label'] }}</span>
                    @else
                        <a href="{{ $item['url'] }}" class="link">{{ $item['label'] }}</a>
                    @endif
                    @unless($loop->last)<x-ui.icon name="chevron" :size="12" class="rtl:rotate-180 text-subtle" />@endunless
                </li>
            @endforeach
        </ol>
    </nav>
@endif
