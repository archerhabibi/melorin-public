<x-filament-panels::page>
    <div wire:poll.30s class="space-y-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div class="grid flex-1 gap-3 md:grid-cols-2">
                <x-filament::input.wrapper>
                    <x-filament::input wire:model.live.debounce.300ms="search" placeholder="جست‌وجوی نام، ایمیل یا slug" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <select wire:model.live="status" class="fi-select-input block w-full border-0 bg-transparent py-1.5 text-base text-gray-950 outline-none focus:ring-0 dark:text-white sm:text-sm">
                        <option value="all">همه وضعیت‌ها</option>
                        <option value="active">فعال</option>
                        <option value="inactive">غیرفعال</option>
                    </select>
                </x-filament::input.wrapper>
            </div>
        </div>

        @php($summary = $this->getSummary())
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ([
                ['label' => 'کل نمایندگان', 'value' => $summary['total'], 'color' => 'gray'],
                ['label' => 'فعال', 'value' => $summary['active'], 'color' => 'success'],
                ['label' => 'مشتریان فعال', 'value' => $summary['customers'], 'color' => 'info'],
                ['label' => 'درآمد پلتفرم', 'value' => \App\Filament\Pages\ResellerOversight::money($summary['platform_revenue']), 'color' => 'success'],
                ['label' => 'پرداخت مشتریان', 'value' => \App\Filament\Pages\ResellerOversight::money($summary['customer_revenue']), 'color' => 'info'],
                ['label' => 'سفارش‌های شکست‌خورده', 'value' => $summary['failed_orders'], 'color' => 'danger'],
            ] as $card)
                <x-filament::section>
                    <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-2 text-xl font-semibold">{{ $card['value'] }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="نمایندگان" description="عملکرد، مشتریان، وضعیت و اعداد مالی هر فروشگاه در یک نگاه.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-gray-500"><th class="p-3">نماینده</th><th class="p-3">وضعیت</th><th class="p-3">مشتریان</th><th class="p-3">سفارش موفق</th><th class="p-3">درآمد پلتفرم</th><th class="p-3">فروش مشتری</th><th class="p-3">موجودی</th><th class="p-3"></th></tr></thead>
                    <tbody>
                    @forelse ($this->getResellers() as $reseller)
                        <tr class="border-b last:border-0">
                            <td class="p-3"><a href="{{ \App\Filament\Pages\ResellerOversight::resellerUrl($reseller) }}" class="font-medium hover:underline">{{ $reseller->getFilamentName() }}</a><div class="text-xs text-gray-500">{{ $reseller->slug }} · {{ $reseller->user?->email }}</div></td>
                            <td class="p-3"><x-filament::badge :color="$reseller->status === 'active' ? 'success' : 'gray'">{{ \App\Filament\Pages\ResellerOversight::statusLabel($reseller->status) }}</x-filament::badge></td>
                            <td class="p-3">{{ $reseller->customers_count }}</td>
                            <td class="p-3">{{ $reseller->completed_orders_count }}</td>
                            <td class="p-3">{{ \App\Filament\Pages\ResellerOversight::money($reseller->platform_revenue) }}</td>
                            <td class="p-3">{{ \App\Filament\Pages\ResellerOversight::money($reseller->customer_revenue) }}</td>
                            <td class="p-3">{{ \App\Filament\Pages\ResellerOversight::money($reseller->wallet?->balance) }}</td>
                            <td class="p-3 text-right"><a class="text-primary-600 hover:underline" href="{{ \App\Filament\Pages\ResellerOversight::resellerUrl($reseller) }}">جزئیات</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-6 text-center text-gray-500">نماینده‌ای مطابق فیلتر پیدا نشد.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="فعالیت اخیر نمایندگان">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-gray-500"><th class="p-3">سفارش</th><th class="p-3">نماینده</th><th class="p-3">محصول</th><th class="p-3">وضعیت</th><th class="p-3">زمان</th></tr></thead>
                    <tbody>
                    @foreach ($this->getRecentActivity() as $order)
                        <tr class="border-b last:border-0"><td class="p-3"><a class="hover:underline" href="{{ \App\Filament\Pages\ResellerOversight::orderUrl($order) }}">#{{ $order->id }}</a></td><td class="p-3">{{ $order->reseller?->getFilamentName() ?: '—' }}</td><td class="p-3">{{ $order->product?->name ?: '—' }}</td><td class="p-3">{{ \App\Filament\Pages\ResellerOversight::orderStatusLabel($order->status) }}</td><td class="p-3">{{ $order->updated_at?->format('Y-m-d H:i') }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
