@extends('website.layouts.app')

@section('title', ($store->isReseller() ? $store->label().' — ' : '').'محصولات')

@section('content')
    <h1 class="text-2xl font-bold mb-6">تعرفه‌ها</h1>

    @if($categories->isEmpty())
        <p class="text-muted">در حال حاضر هیچ تعرفه‌ی فعالی موجود نیست.</p>
    @endif

    <div class="space-y-10">
        @foreach($categories as $category)
            <section>
                <h2 class="text-lg font-semibold mb-4">{{ $category->name }}</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach($category->products as $product)
                        <a href="{{ $store->isReseller() ? route('website.store.products.show', ['slug' => $store->reseller->slug, 'product' => $product->id]) : route('website.products.show', $product) }}"
                           class="block rounded-lg border bg-surface p-4 hover:shadow transition">
                            <div class="font-medium">{{ $product->name }}</div>
                            <div class="text-sm text-muted mt-1">
                                {{ $product->duration_days }} روزه
                                @if($product->traffic_gb) · {{ $product->traffic_gb }} گیگابایت @endif
                            </div>
                            <div class="text-brand mt-3 font-bold">
                                {{ \App\Support\Money::format($catalog->displayPrice($product, $store)) }}
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
