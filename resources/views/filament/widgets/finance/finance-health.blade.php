<x-filament-widgets::widget>
    <x-filament::section heading="سلامت مالی" icon="heroicon-o-heart">
        @forelse ($alerts as $item)
            @php($alert = $item['alert'])
            <div @class([
                'flex items-start justify-between gap-4 py-3',
                'border-t border-gray-200 dark:border-white/10' => ! $loop->first,
            ]) data-alert="{{ $alert->key }}">
                <div class="flex items-start gap-3">
                    <x-filament::badge :color="$alert->tone">
                        {{ $alert->tone === 'danger' ? 'مهم' : ($alert->tone === 'warning' ? 'هشدار' : 'اطلاع') }}
                    </x-filament::badge>
                    <div>
                        <div class="text-sm font-medium">{{ $alert->title }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $alert->renderBody(fn (int $minor) => \App\Support\Money::format($minor)) }}</div>
                    </div>
                </div>
                @if ($item['url'])
                    <x-filament::link :href="$item['url']" size="sm">مشاهده</x-filament::link>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400" data-alert="none">
                سلامت مالی سیستم خوب است.
            </p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
