<x-filament-widgets::widget>
    <x-filament::section heading="برترین معرف‌ها" description="بر اساس مجموع پرداختی در همین فیلتر" icon="heroicon-o-trophy">
        @if (count($referrers) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400" data-top-referrers="none">
                هنوز معرفی کمیسیون نگرفته است.
            </p>
        @else
            <div class="overflow-x-auto" data-top-referrers="list">
                <table class="w-full text-start text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 text-start font-medium">معرف</th>
                            <th class="py-2 text-start font-medium">کمیسیون</th>
                            <th class="py-2 text-start font-medium">پاداش معرفی</th>
                            <th class="py-2 text-start font-medium">مجموع</th>
                            <th class="py-2 text-start font-medium">مشتریِ معرفی‌شده</th>
                            <th class="py-2 text-start font-medium">آخرین پرداخت</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($referrers as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10" data-referrer="{{ $row->userId }}">
                                <td class="py-2">
                                    <div class="font-medium">{{ $row->name }}</div>
                                    @if ($row->contact)
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->contact }}</div>
                                    @endif
                                </td>
                                <td class="py-2">{{ \App\Support\Money::format($row->commissionAmount) }} <span class="text-xs text-gray-500">({{ number_format($row->commissionCount) }})</span></td>
                                <td class="py-2">{{ \App\Support\Money::format($row->bonusAmount) }}</td>
                                <td class="py-2 font-semibold">{{ \App\Support\Money::format($row->totalAmount()) }}</td>
                                <td class="py-2">{{ number_format($row->referredCustomers) }}</td>
                                <td class="py-2">{{ $row->lastPaidAt ? \App\Support\JalaliDate::format($row->lastPaidAt) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
