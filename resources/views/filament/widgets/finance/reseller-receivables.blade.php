<x-filament-widgets::widget>
    <x-filament::section heading="مطالبات نمایندگان" icon="heroicon-o-document-currency-dollar">
        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                هیچ نماینده‌ای مطالبه ندارد.
            </p>
        @else
            <div class="space-y-3">
                @foreach ($rows as $row)
                    <div class="flex items-center justify-between rounded border border-gray-200 p-3 dark:border-white/10" data-debtor="{{ $row->resellerId }}">
                        <div>
                            <div class="font-medium">{{ $row->name !== '' ? $row->name : '—' }}</div>
                            @if ($row->slug)
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->slug }}</div>
                            @endif
                        </div>
                        <div class="text-end">
                            <div class="font-mono font-medium">{{ \App\Support\Money::format($row->balance) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">سقف: {{ \App\Support\Money::format($row->limit) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
