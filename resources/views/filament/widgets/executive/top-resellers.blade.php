<x-filament-widgets::widget>
    <x-filament::section heading="برترین نمایندگان ({{ $period->label() }})" icon="heroicon-o-trophy">
        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400" data-top-resellers="none">
                در این بازه فروش نمایندگی ثبت نشده است.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-top-resellers="list">
                    <thead>
                        <tr class="text-start text-gray-500 dark:text-gray-400">
                            <th class="py-2 text-start font-medium">نماینده</th>
                            <th class="py-2 text-start font-medium">سفارش</th>
                            <th class="py-2 text-start font-medium">درآمد پلتفرم</th>
                            <th class="py-2 text-start font-medium">سود نماینده</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10" data-reseller="{{ $row->resellerId }}">
                                <td class="py-2">
                                    <div class="font-medium">{{ $row->name !== '' ? $row->name : '—' }}</div>
                                    @if ($row->slug)
                                        <div class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $row->slug }}</div>
                                    @endif
                                </td>
                                <td class="py-2">{{ number_format($row->orders) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->platformRevenue) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->margin()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
