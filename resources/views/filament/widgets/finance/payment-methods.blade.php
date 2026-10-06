<x-filament-widgets::widget>
    <x-filament::section heading="دریافت‌ها ({{ $period->label() }})" icon="heroicon-o-credit-card">
        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                در این بازه تراکنش ثبت نشده است.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-payment-methods="list">
                    <thead>
                        <tr class="text-start text-gray-500 dark:text-gray-400">
                            <th class="py-2 text-start font-medium">روش</th>
                            <th class="py-2 text-start font-medium">تراکنش</th>
                            <th class="py-2 text-start font-medium">شارژ‌شده</th>
                            <th class="py-2 text-start font-medium">درآمد پلتفرم</th>
                            <th class="py-2 text-start font-medium">بازگشت</th>
                            <th class="py-2 text-start font-medium">خالص</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10" data-method="{{ $row->type }}">
                                <td class="py-2 font-medium">{{ \App\Filament\Widgets\Finance\PaymentMethodBreakdown::typeLabel($row->type) }}</td>
                                <td class="py-2">{{ number_format($row->count) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->charged) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->platformFees) }}</td>
                                <td class="py-2">{{ \App\Support\Money::format($row->refunded) }}</td>
                                <td class="py-2 font-medium">{{ \App\Support\Money::format($row->net()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
