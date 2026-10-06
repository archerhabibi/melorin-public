<x-filament-widgets::widget>
    <x-filament::section heading="پرمعرفی‌ترین‌ها" description="بر اساس تعداد عضو معرفی‌شده در همین فیلتر" icon="heroicon-o-trophy">
        @if (count($inviters) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400" data-top-inviters="none">هنوز معرفی‌ای ثبت نشده است.</p>
        @else
            <div class="overflow-x-auto" data-top-inviters="list">
                <table class="w-full text-start text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 text-start font-medium">معرف</th>
                            <th class="py-2 text-start font-medium">معرفی‌شده</th>
                            <th class="py-2 text-start font-medium">خریدار</th>
                            <th class="py-2 text-start font-medium">نرخ تبدیل</th>
                            <th class="py-2 text-start font-medium">کمیسیون</th>
                            <th class="py-2 text-start font-medium">پاداش معرفی</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inviters as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10" data-inviter="{{ $row->userId }}">
                                <td class="py-2">
                                    <div class="font-medium">{{ $row->name }}</div>
                                    @if ($row->contact)
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row->contact }}</div>
                                    @endif
                                </td>
                                <td class="py-2 font-semibold">{{ number_format($row->invited) }}</td>
                                <td class="py-2">{{ number_format($row->converted) }}</td>
                                <td class="py-2">{{ $row->conversionPercent() === null ? '—' : number_format($row->conversionPercent()).'٪' }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->commissionEarned) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->bonusEarned) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
