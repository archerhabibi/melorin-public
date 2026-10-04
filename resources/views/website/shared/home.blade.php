@extends('website.layouts.app')

@section('title', ($store->isReseller() ? $store->label().' — ' : '').'محصولات')

{{-- نتیجه‌ی جست‌وجو/فیلتر یک صفحه‌ی «مشتق» است، نه صفحه‌ی اصلی؛ ایندکس نشود (لینک‌ها دنبال شوند). --}}
@if($catalog->query->isFiltered() || $catalog->query->sort !== \App\Services\Core\Catalog\CatalogQuery::SORT_DEFAULT)
    @push('head')<meta name="robots" content="noindex,follow">@endpush
@endif

@section('content')
    @php($q = $catalog->query)

    <h1 class="mb-6 text-2xl font-bold">تعرفه‌ها</h1>

    @if($catalog->isEmpty())
        <p class="text-muted">در حال حاضر هیچ تعرفه‌ی فعالی موجود نیست.</p>
    @else
        {{-- B4.1 — جست‌وجو و فیلتر: GET ساده، بدون JS؛ همه‌ی قواعد در Core (CatalogQuery). --}}
        <form method="GET" action="{{ $route('home') }}" role="search" aria-label="جست‌وجوی تعرفه‌ها"
              class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="f-q" class="label">جست‌وجو</label>
                <input id="f-q" type="search" name="q" value="{{ $q->search }}" maxlength="{{ \App\Services\Core\Catalog\CatalogQuery::SEARCH_MAX }}"
                       class="input" placeholder="نام تعرفه یا سبد، مثلاً ۳۰ روزه">
            </div>
            <div>
                <label for="f-category" class="label">سبد فروش</label>
                <select id="f-category" name="category" class="input">
                    <option value="">همه</option>
                    @foreach($catalog->options as $option)
                        <option value="{{ $option['id'] }}" @selected($q->categoryId === $option['id'])>{{ $option['name'] }} ({{ $option['count'] }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-sort" class="label">مرتب‌سازی</label>
                <select id="f-sort" name="sort" class="input">
                    @foreach(\App\Services\Core\Catalog\CatalogQuery::sortLabels() as $value => $label)
                        <option value="{{ $value }}" @selected($q->sort === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="available" value="1" @checked($q->availableOnly)>
                    فقط تعرفه‌های موجود
                </label>
                <x-ui.button type="submit" size="sm">اعمال</x-ui.button>
                @if($q->isFiltered() || $q->sort !== \App\Services\Core\Catalog\CatalogQuery::SORT_DEFAULT)
                    <a href="{{ $route('home') }}" class="text-sm text-muted underline">پاک‌کردن فیلترها</a>
                @endif
                @if($q->isFiltered())
                    <span class="text-xs text-muted" role="status">{{ $catalog->totalMatched }} تعرفه از {{ $catalog->totalVisible }}</span>
                @endif
            </div>
        </form>

        @if($catalog->hasNoMatches())
            <x-ui.empty-state icon="info" title="تعرفه‌ای با این مشخصات پیدا نشد">
                عبارت دیگری را امتحان کنید یا <a href="{{ $route('home') }}" class="underline">همه‌ی تعرفه‌ها</a> را ببینید.
            </x-ui.empty-state>
        @elseif($catalog->isGrouped())
            <div class="space-y-10">
                @foreach($catalog->categories as $group)
                    <section aria-labelledby="cat-{{ $group->category->id }}">
                        <h2 id="cat-{{ $group->category->id }}" class="mb-4 text-lg font-semibold">{{ $group->category->name }}</h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                            @foreach($group->items as $item)
                                @include('website.shared._catalog-card', ['item' => $item, 'route' => $route])
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @else
            {{-- مرتب‌سازی سراسری: یک فهرست؛ نام سبد روی هر کارت نمی‌آید تا شلوغ نشود، ولی زیر نام آمده است. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                @foreach($catalog->items as $item)
                    @include('website.shared._catalog-card', ['item' => $item, 'route' => $route])
                @endforeach
            </div>
        @endif
    @endif
@endsection
