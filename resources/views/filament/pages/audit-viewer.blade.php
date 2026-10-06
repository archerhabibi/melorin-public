<x-filament-panels::page>
    <div wire:poll.30s class="space-y-6">
        <div class="grid gap-3 md:grid-cols-4">
            <x-filament::input.wrapper class="md:col-span-2">
                <x-filament::input wire:model.live.debounce.300ms="search" placeholder="جست‌وجوی عملیات، موضوع، شناسه یا عامل" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <select wire:model.live="actorType" class="fi-select-input block w-full border-0 bg-transparent py-1.5 text-base text-gray-950 outline-none focus:ring-0 dark:text-white sm:text-sm">
                    <option value="all">همه عامل‌ها</option>
                    <option value="admin">ادمین</option>
                    <option value="reseller">نماینده</option>
                    <option value="customer">کاربر</option>
                    <option value="system">سیستم</option>
                </select>
            </x-filament::input.wrapper>
            <div class="flex gap-2">
                <x-filament::input.wrapper class="flex-1"><x-filament::input type="date" wire:model.live="dateFrom" /></x-filament::input.wrapper>
                <x-filament::input.wrapper class="flex-1"><x-filament::input type="date" wire:model.live="dateTo" /></x-filament::input.wrapper>
            </div>
        </div>

        @php($summary = $this->getSummary())
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['کل رویدادها', $summary['total']], ['اقدامات ادمین', $summary['admin']], ['اقدامات نماینده', $summary['reseller']], ['اقدامات سیستم', $summary['system']]] as $card)
                <x-filament::section><div class="text-sm text-gray-500">{{ $card[0] }}</div><div class="mt-2 text-xl font-semibold">{{ $card[1] }}</div></x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Audit Timeline" description="آخرین ۲۰۰ رویداد مطابق فیلترها. before و after برای بررسی دقیق تغییرات نگه داشته می‌شوند.">
            <div class="space-y-4">
                @forelse ($this->getLogs() as $log)
                    <article class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="$log->actor_type === 'admin' ? 'primary' : ($log->actor_type === 'reseller' ? 'warning' : 'gray')">{{ \App\Filament\Pages\AuditViewer::actorLabel($log->actor_type) }} #{{ $log->actor_id }}</x-filament::badge>
                                    <span class="font-semibold">{{ $log->action }}</span>
                                </div>
                                <div class="mt-1 text-sm text-gray-500">{{ \App\Filament\Pages\AuditViewer::targetLabel($log->target_type) }} #{{ $log->target_id ?? '—' }} · {{ $log->created_at?->format('Y-m-d H:i:s') }}</div>
                            </div>
                            <span class="text-xs text-gray-500">IP: {{ $log->ip_address ?: '—' }}</span>
                        </div>
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-primary-600">نمایش جزئیات تغییر</summary>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                <div><div class="mb-1 text-xs text-gray-500">قبل</div><pre class="max-h-56 overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ \App\Filament\Pages\AuditViewer::jsonValue($log->before) }}</pre></div>
                                <div><div class="mb-1 text-xs text-gray-500">بعد</div><pre class="max-h-56 overflow-auto rounded bg-gray-50 p-3 text-xs dark:bg-gray-800">{{ \App\Filament\Pages\AuditViewer::jsonValue($log->after) }}</pre></div>
                            </div>
                        </details>
                    </article>
                @empty
                    <div class="p-6 text-center text-gray-500">رویدادی مطابق فیلترها پیدا نشد.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
