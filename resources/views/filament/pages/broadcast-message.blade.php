<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <div class="mt-4">
            {{-- wire:loading تا کاربر بداند درخواستش ثبت شده و روی دکمه
                 چند بار پشت‌سرهم نزند (که باعث چند بار dispatch شدن
                 همان پیام همگانی می‌شد). --}}
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="send">ارسال به همه‌ی کاربران</span>
                <span wire:loading wire:target="send">در حال ارسال…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
