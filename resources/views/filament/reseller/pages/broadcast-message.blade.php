<x-filament-panels::page>
    <form wire:submit="send">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="send">ارسال به همه‌ی مشتریان من</span>
                <span wire:loading wire:target="send">در حال ارسال…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
