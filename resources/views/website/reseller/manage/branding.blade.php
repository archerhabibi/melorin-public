@extends('website.layouts.app')

@section('title', 'تنظیمات فروشگاه')

@section('content')
    <h1 class="text-xl font-bold">تنظیمات فروشگاه {{ $reseller->getFilamentName() }}</h1>
    <p class="text-sm text-muted mt-1">این اطلاعات فقط برای نمایش است؛ روی قیمت‌گذاری یا مجوزها اثری ندارد.</p>

    @if($errors->any())
        <div class="mt-4 rounded border border-danger/30 bg-danger-soft text-danger px-4 py-3 text-sm">
            <ul class="list-disc pr-4">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('website.store.manage.branding.update', $store->reseller->slug) }}"
          enctype="multipart/form-data" class="mt-6 bg-surface border rounded-lg p-6 max-w-lg space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-text">نام نمایشی فروشگاه</label>
            <input type="text" name="display_name" maxlength="100"
                   value="{{ old('display_name', $setting?->display_name) }}"
                   placeholder="{{ $reseller->getFilamentName() }}"
                   class="mt-1 w-full border rounded px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-text">لوگو</label>
            @if($branding['logo_url'])
                <img src="{{ $branding['logo_url'] }}" alt="لوگوی فعلی" class="h-12 w-12 rounded object-contain border mt-1 mb-2">
            @endif
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-1 w-full text-sm">
            <p class="text-xs text-subtle mt-1">PNG، JPG یا WebP — حداکثر ۱ مگابایت.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-text">رنگ اصلی</label>
            <input type="color" name="brand_color" value="{{ old('brand_color', $setting?->brand_color ?: '#2563eb') }}"
                   class="mt-1 h-10 w-20 border rounded">
        </div>

        <div>
            <label class="block text-sm font-medium text-text">شماره تماس</label>
            <input type="text" name="contact_phone" maxlength="30"
                   value="{{ old('contact_phone', $setting?->contact_phone) }}"
                   class="mt-1 w-full border rounded px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-text">ایمیل تماس</label>
            <input type="email" name="contact_email" maxlength="190"
                   value="{{ old('contact_email', $setting?->contact_email) }}"
                   class="mt-1 w-full border rounded px-3 py-2 text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-text">درباره‌ی فروشگاه</label>
            <textarea name="about_text" rows="3" maxlength="2000"
                      class="mt-1 w-full border rounded px-3 py-2 text-sm">{{ old('about_text', $setting?->about_text) }}</textarea>
        </div>

        <button type="submit" class="bg-brand px-4 py-2 rounded text-on-brand text-sm font-medium">
            ذخیره‌ی تغییرات
        </button>
    </form>
@endsection
