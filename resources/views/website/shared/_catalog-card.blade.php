{{-- کارت یک تعرفه (B4.1). ورودی: $item (CatalogItem)، $route. قیمت نهایی از Core است؛ اینجا هیچ محاسبه‌ای نیست. --}}
@php($soldOut = $item->isSoldOut())
<a href="{{ $route('products.show', ['product' => $item->id()]) }}"
   class="block rounded-lg border bg-surface p-4 transition hover:shadow {{ $soldOut ? 'opacity-70' : '' }}"
   @if($soldOut) aria-label="{{ $item->product->name }} — ظرفیت تکمیل" @endif>
    <div class="flex items-start justify-between gap-2">
        <div class="font-medium">{{ $item->product->name }}</div>
        @if($soldOut)
            <x-ui.badge tone="danger">ظرفیت تکمیل</x-ui.badge>
        @elseif($item->isLowStock())
            <x-ui.badge tone="warning">{{ $item->remaining() }} عدد باقی مانده</x-ui.badge>
        @endif
    </div>
    <div class="mt-1 text-sm text-muted">
        {{ $item->durationLabel() }} · {{ $item->trafficLabel() }}
    </div>
    <div class="mt-3 font-bold {{ $soldOut ? 'text-muted' : 'text-brand' }}">
        {{ \App\Support\Money::format($item->price) }}
    </div>
</a>
