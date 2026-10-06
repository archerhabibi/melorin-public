@extends('website.layouts.app')

@section('title', 'تنظیمات فروشگاه')

{{--
    B5.7 — White Label Foundation. این صفحه فقط UI است: پاک‌سازی/لوگو/Audit در
    ResellerBrandingService (Core). `$brand` همان منبعی است که Layout می‌خواند، پس
    «پیش‌نمایش» همیشه با آنچه مشتری می‌بیند یکی است.
--}}
@section('content')
    <x-ui.breadcrumb :items="[
        ['label' => $brand->name, 'url' => route('website.store.home', $store->reseller->slug)],
        ['label' => 'تنظیمات فروشگاه'],
    ]" />

    <x-ui.page-header title="تنظیمات فروشگاه"
        subtitle="ظاهر و هویت فروشگاه شما. این اطلاعات فقط برای نمایش است؛ روی قیمت‌گذاری یا مجوزها اثری ندارد." />

    @if($errors->any())
        <x-ui.alert type="danger" class="mb-6">
            <ul class="list-disc pr-4">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('website.store.manage.branding.update', $store->reseller->slug) }}"
              enctype="multipart/form-data" data-submit-lock class="lg:col-span-2 space-y-6">
            @csrf

            <x-ui.card class="space-y-4">
                <h2 class="font-bold">هویت فروشگاه</h2>

                <x-ui.field name="display_name" label="نام نمایشی فروشگاه" maxlength="100"
                            :value="$setting?->display_name" :placeholder="$reseller->getFilamentName()"
                            hint="در سربرگ، عنوان صفحه‌ها و پنل مدیریت شما نشان داده می‌شود." />

                <div>
                    <label class="label" for="f-logo">لوگو</label>
                    @if($brand->logoUrl)
                        <div class="mt-1 mb-2 flex items-center gap-3">
                            <img src="{{ $brand->logoUrl }}" alt="لوگوی فعلی" class="h-12 w-12 rounded object-contain border">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="remove_logo" value="1" @checked(old('remove_logo'))>
                                حذف لوگوی فعلی
                            </label>
                        </div>
                    @endif
                    <input id="f-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-1 w-full text-sm">
                    <p class="hint">PNG، JPG یا WebP — حداکثر ۱ مگابایت و ۲۰۰۰×۲۰۰۰ پیکسل. لوگوی مربعی بهتر دیده می‌شود.</p>
                </div>

                <div>
                    <label class="label" for="f-brand_color">رنگ اصلی</label>
                    <input id="f-brand_color" type="color" name="brand_color" value="{{ old('brand_color', $setting?->brand_color ?: '#2563eb') }}"
                           class="mt-1 h-10 w-20 border rounded">
                    <p class="hint">رنگ متن روی دکمه‌ها خودکار طوری انتخاب می‌شود که خوانا بماند.</p>
                </div>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <h2 class="font-bold">اطلاعات تماس و معرفی</h2>

                <x-ui.field name="contact_phone" label="شماره تماس" maxlength="30" dir="ltr" :value="$setting?->contact_phone" />
                <x-ui.field name="contact_email" label="ایمیل تماس" type="email" maxlength="190" dir="ltr" :value="$setting?->contact_email" />

                <div>
                    <label class="label" for="f-about_text">درباره‌ی فروشگاه</label>
                    <textarea id="f-about_text" name="about_text" rows="3" maxlength="2000" class="input">{{ old('about_text', $setting?->about_text) }}</textarea>
                </div>
            </x-ui.card>

            <x-ui.card class="space-y-4">
                <h2 class="font-bold">موتورهای جست‌وجو (SEO)</h2>

                <input type="hidden" name="allow_indexing" value="0">
                <label class="flex items-start gap-2 text-sm">
                    <input id="f-allow_indexing" type="checkbox" name="allow_indexing" value="1" class="mt-1"
                           @checked(old('allow_indexing', $setting?->allow_indexing))>
                    <span>
                        اجازه‌ی ایندکس شدن فروشگاه در گوگل و سایر موتورهای جست‌وجو
                        <span class="hint block">پیش‌فرض خاموش است و فروشگاه شما در نتایج جست‌وجو نمی‌آید. صفحات ورود، حساب کاربری و پرداخت در هر حالت ایندکس نمی‌شوند.</span>
                    </span>
                </label>

                @unless($readiness->indexingReady())
                    <x-ui.alert type="warning">
                        پیش از روشن‌کردن ایندکس، بهتر است نام نمایشی اختصاصی و متن معرفی فروشگاه را تکمیل کنید؛ وگرنه فروشگاه با نام فنیِ پیش‌فرض در نتایج دیده می‌شود.
                    </x-ui.alert>
                @endunless

                <div>
                    <label class="label" for="f-meta_description">توضیح کوتاه برای نتایج جست‌وجو</label>
                    <textarea id="f-meta_description" name="meta_description" rows="2" maxlength="300" class="input">{{ old('meta_description', $setting?->meta_description) }}</textarea>
                    <p class="hint">حداکثر ۳۰۰ نویسه؛ فقط وقتی ایندکس روشن باشد در صفحه چاپ می‌شود.</p>
                </div>
            </x-ui.card>

            <x-ui.button type="submit">ذخیره‌ی تغییرات</x-ui.button>
        </form>

        <aside class="space-y-6" aria-label="وضعیت برندینگ">
            <x-ui.card>
                <h2 class="font-bold">پیش‌نمایش</h2>
                <div class="mt-3 flex items-center gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-brand font-bold">
                    @if($brand->logoUrl)
                        <img src="{{ $brand->logoUrl }}" alt="" class="h-8 w-8 rounded object-contain">
                    @endif
                    <span>{{ $brand->name }}</span>
                </div>
                <div class="mt-3"><span class="btn btn-primary btn-sm">دکمه‌ی نمونه</span></div>
                <p class="hint mt-2">مطابق آخرین ذخیره؛ بعد از ذخیره‌ی تغییرات به‌روز می‌شود.</p>
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold">تکمیل برندینگ</h2>
                    <span class="text-sm tabular">{{ $readiness->doneCount() }} از {{ $readiness->total() }}</span>
                </div>
                <x-ui.progress class="mt-3" :value="$readiness->percent()" :tone="$readiness->percent() >= 80 ? 'success' : 'warning'" label="تکمیل برندینگ" />
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach($readiness->items as $item)
                        <li class="flex items-center gap-2">
                            <x-ui.badge :tone="$item['done'] ? 'success' : 'neutral'">{{ $item['done'] ? 'انجام شد' : 'مانده' }}</x-ui.badge>
                            <span>{{ $item['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-muted">
                    وضعیت ایندکس:
                    <strong>{{ $brand->allowIndexing ? 'روشن' : 'خاموش' }}</strong>
                </p>
            </x-ui.card>
        </aside>
    </div>
@endsection
