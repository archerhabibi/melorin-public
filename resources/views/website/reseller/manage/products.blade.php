@extends('website.layouts.app')

@section('title', 'محصولات فروشگاه')

@section('content')
    <h1 class="text-xl font-bold">محصولات فروشگاه {{ $reseller->getFilamentName() }}</h1>
    <p class="text-sm text-muted mt-1">
        برای هر محصول یک قیمتِ فروش (customers_price) تعیین کنید تا برای مشتریان شما فعال شود.
        هزینه‌ی تأمینِ Core (reseller_price) از کیف‌پول اصلی شما در Main کسر می‌شود، نه از این فروشگاه.
    </p>

    @error('customers_price')
        <div class="mt-4 rounded border border-danger/30 bg-danger-soft text-danger px-4 py-3 text-sm">{{ $message }}</div>
    @enderror
    @error('product')
        <div class="mt-4 rounded border border-danger/30 bg-danger-soft text-danger px-4 py-3 text-sm">{{ $message }}</div>
    @enderror

    <div class="mt-6 bg-surface border rounded-lg overflow-hidden">
        <table class="w-full text-sm text-right">
            <thead class="bg-surface-2 text-muted border-b">
                <tr>
                    <th class="px-4 py-2 font-medium">محصول</th>
                    <th class="px-4 py-2 font-medium">دسته‌بندی</th>
                    <th class="px-4 py-2 font-medium">هزینه‌ی تأمین (reseller_price)</th>
                    <th class="px-4 py-2 font-medium">قیمت فروش شما (customers_price)</th>
                    <th class="px-4 py-2 font-medium">وضعیت</th>
                    <th class="px-4 py-2 font-medium">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-4 py-3">{{ $row['product']->name }}</td>
                        <td class="px-4 py-3 text-muted">{{ $row['product']->category->name }}</td>
                        <td class="px-4 py-3 text-muted">{{ \App\Support\Money::format($row['reseller_price']) }}</td>
                        <td class="px-4 py-3">
                            <form method="POST"
                                  action="{{ route('website.store.manage.products.price', [$store->reseller->slug, $row['product']->id]) }}"
                                  class="flex items-center gap-2">
                                @csrf
                                <input type="number" name="customers_price" min="0" step="{{ \App\Support\Money::inputStep() }}"
                                       value="{{ old('customers_price', $row['customers_price'] === null ? null : \App\Support\Money::toMajorString($row['customers_price'])) }}"
                                       class="w-28 border rounded px-2 py-1 text-sm">
                                <button type="submit" class="bg-brand text-xs px-2 py-1 rounded text-on-brand">ثبت</button>
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            @if($row['is_enabled'])
                                <span class="text-success bg-success-soft border border-success/30 rounded px-2 py-0.5 text-xs">فعال</span>
                            @else
                                <span class="text-muted bg-surface-2 border border-border rounded px-2 py-0.5 text-xs">غیرفعال</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($row['is_enabled'])
                                <form method="POST" action="{{ route('website.store.manage.products.disable', [$store->reseller->slug, $row['product']->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-xs text-danger hover:underline">غیرفعال کردن</button>
                                </form>
                            @elseif($row['customers_price'] !== null)
                                <form method="POST" action="{{ route('website.store.manage.products.enable', [$store->reseller->slug, $row['product']->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-xs text-success hover:underline">فعال کردن</button>
                                </form>
                            @else
                                <span class="text-xs text-subtle">ابتدا قیمت تعیین کنید</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-subtle">در حال حاضر محصولی برای تعیین قیمت در دسترس نیست.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
