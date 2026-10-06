<x-filament-panels::page>
    <div wire:poll.30s class="space-y-6">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($this->getPanels() as $panel)
                @php($node = $this->getNodeStatus($panel))
                <x-filament::section>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <a href="{{ \App\Filament\Pages\ProvisioningOperations::panelUrl($panel) }}" class="font-semibold hover:underline">
                                {{ $panel->name }}
                            </a>
                            <div class="text-sm text-gray-500">{{ $panel->panel_type }} · {{ $panel->host }}</div>
                        </div>
                        <x-filament::badge :color="$node['state'] === 'healthy' ? 'success' : ($node['state'] === 'down' ? 'danger' : 'gray')">
                            {{ $node['label'] }}
                        </x-filament::badge>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-gray-500">اکانت فعال</span><br><strong>{{ $panel->active_accounts_count }}</strong></div>
                        <div><span class="text-gray-500">ظرفیت</span><br><strong>{{ $panel->capacity ?? 'نامحدود' }}</strong></div>
                    </div>
                    @if (! empty($node['details']))
                        <dl class="mt-4 space-y-1 text-xs text-gray-500">
                            @foreach ($node['details'] as $key => $value)
                                <div class="flex justify-between gap-3"><dt>{{ $key }}</dt><dd class="text-right">{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="صف Provisioning" description="سفارش‌های در حال ساخت یا نیازمند رسیدگی، بدون ایجاد Debit جدید در retry.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-left text-gray-500"><th class="p-3">سفارش</th><th class="p-3">کاربر</th><th class="p-3">محصول</th><th class="p-3">وضعیت</th><th class="p-3">تلاش</th><th class="p-3">آخرین خطا</th><th class="p-3"></th></tr></thead>
                    <tbody>
                    @forelse ($this->getQueue() as $order)
                        <tr class="border-b last:border-0">
                            <td class="p-3"><a class="font-medium hover:underline" href="{{ \App\Filament\Pages\ProvisioningOperations::orderUrl($order) }}">#{{ $order->id }}</a></td>
                            <td class="p-3">{{ $order->user?->full_name ?: $order->user?->email ?: '—' }}</td>
                            <td class="p-3">{{ $order->product?->name ?: '—' }}</td>
                            <td class="p-3"><x-filament::badge :color="$order->status === 'provision_failed' ? 'danger' : 'warning'">{{ \App\Filament\Pages\ProvisioningOperations::statusLabel($order->status) }}</x-filament::badge></td>
                            <td class="p-3">{{ $order->provision_attempts ?? 0 }}</td>
                            <td class="max-w-sm truncate p-3 text-gray-500" title="{{ $order->failure_reason }}">{{ $order->failure_reason ?: '—' }}</td>
                            <td class="p-3 text-right">
                                @if ($order->status === 'provision_failed')
                                    <x-filament::button size="sm" color="warning" wire:click="retry({{ $order->id }})" wire:loading.attr="disabled">تلاش مجدد</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-6 text-center text-gray-500">صف Provisioning خالی است.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
