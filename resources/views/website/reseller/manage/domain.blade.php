@extends('website.layouts.app')

@section('title', 'دامنه‌ی اختصاصی')

{{--
    B6.1 — Custom Domain. فقط UI: نرمال‌سازی، یکتایی، تأیید DNS و Audit در ResellerDomainService (Core).
    فقط دامنه‌ی «تأییدشده» مسیریابی می‌شود؛ تا قبل از آن فروشگاه همچنان روی /store/{slug} است.
--}}
@section('content')
    <x-ui.breadcrumb :items="[
        ['label' => $brand->name, 'url' => route('website.store.home', $store->reseller->slug)],
        ['label' => 'دامنه‌ی اختصاصی'],
    ]" />

    <x-ui.page-header title="دامنه‌ی اختصاصی"
        subtitle="فروشگاه خود را روی دامنه‌ی خودتان (مثلاً shop.example.com) منتشر کنید." />

    @if($errors->any())
        <x-ui.alert type="danger" class="mb-6">
            <ul class="list-disc pr-4">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @unless($enabled)
        <x-ui.alert type="warning" class="mb-6">قابلیت دامنه‌ی اختصاصی در این نصب غیرفعال است.</x-ui.alert>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <x-ui.card class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-bold">دامنه</h2>
                    @if($domain['domain'])
                        <x-ui.badge :tone="$domain['verified'] ? 'success' : 'warning'">
                            {{ $domain['verified'] ? 'تأیید شده' : 'در انتظار تأیید DNS' }}
                        </x-ui.badge>
                    @endif
                </div>

                <form method="POST" action="{{ route('website.store.manage.domain.save', $store->reseller->slug) }}" data-submit-lock class="space-y-3">
                    @csrf
                    <x-ui.field name="domain" label="نام دامنه" dir="ltr" maxlength="300" placeholder="shop.example.com"
                                :value="$domain['domain']"
                                hint="فقط نام دامنه، بدون https:// و بدون مسیر. تغییر دامنه، تأیید قبلی را باطل می‌کند." />
                    <x-ui.button type="submit" :disabled="! $enabled">{{ $domain['domain'] ? 'تغییر دامنه' : 'ثبت دامنه' }}</x-ui.button>
                </form>
            </x-ui.card>

            @if($domain['domain'] && ! $domain['verified'])
                <x-ui.card class="space-y-4">
                    <h2 class="font-bold">تأیید مالکیت</h2>
                    <p class="text-sm">این رکورد TXT را در تنظیمات DNS دامنه‌ی خود اضافه کنید:</p>
                    <dl class="grid gap-2 text-sm" dir="ltr">
                        <div><dt class="text-muted">Name</dt><dd><code class="select-all">{{ $domain['txt_name'] }}</code></dd></div>
                        <div><dt class="text-muted">Type</dt><dd><code>TXT</code></dd></div>
                        <div><dt class="text-muted">Value</dt><dd><code class="select-all break-all">{{ $domain['txt_value'] }}</code></dd></div>
                    </dl>
                    @if($domain['checked_at'])
                        <p class="text-xs text-muted">آخرین بررسی: {{ $domain['checked_at']->diffForHumans() }}</p>
                    @endif
                    <p class="text-xs text-muted">DNS هر چند دقیقه خودکار بررسی می‌شود؛ پس از ثبت رکورد (تا چند ساعت برای انتشار DNS) نیازی به کلیک نیست.</p>
                    @if($domain['expires_at'])
                        <p class="text-xs text-muted">اگر تا <span dir="ltr">{{ $domain['expires_at']->diffForHumans() }}</span> تأیید نشود، ثبت این دامنه آزاد می‌شود.</p>
                    @endif
                    <form method="POST" action="{{ route('website.store.manage.domain.verify', $store->reseller->slug) }}" data-submit-lock>
                        @csrf
                        <x-ui.button type="submit">بررسی DNS</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if($domain['verified'])
                <x-ui.card class="space-y-3">
                    <h2 class="font-bold">فروشگاه شما روی این دامنه فعال است</h2>
                    <p class="text-sm" dir="ltr"><a href="https://{{ $domain['domain'] }}" rel="noopener" class="underline">https://{{ $domain['domain'] }}</a></p>
                    <p class="text-xs text-muted">رکورد TXT را نگه دارید؛ اگر مدتی دیده نشود، دامنه به «در انتظار تأیید» برمی‌گردد و فروشگاه روی آن سرو نمی‌شود.</p>
                    <p class="text-xs text-muted">آدرس قبلی (<span dir="ltr">/store/{{ $store->reseller->slug }}</span>) همچنان کار می‌کند. ورود با Google روی دامنه‌ی اختصاصی فعلاً در دسترس نیست؛ ورود با ایمیل کار می‌کند.</p>
                </x-ui.card>
            @endif

            @if($domain['domain'])
                <x-ui.card class="space-y-3">
                    <h2 class="font-bold">حذف دامنه</h2>
                    <p class="text-sm">با حذف، فروشگاه فقط روی آدرس پلتفرم در دسترس می‌ماند.</p>
                    <form method="POST" action="{{ route('website.store.manage.domain.remove', $store->reseller->slug) }}" data-submit-lock>
                        @csrf
                        <x-ui.button type="submit" variant="secondary">حذف دامنه‌ی اختصاصی</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <aside class="space-y-6" aria-label="راهنما">
            <x-ui.card class="space-y-3">
                <h2 class="font-bold">مراحل راه‌اندازی</h2>
                <ol class="list-decimal pr-5 space-y-2 text-sm">
                    <li>دامنه را ثبت کنید.</li>
                    <li>رکورد TXT نمایش‌داده‌شده را در DNS اضافه کنید.</li>
                    <li>رکورد <span dir="ltr">CNAME</span> دامنه را به <code dir="ltr">{{ $platformHost }}</code> بزنید (برای دامنه‌ی ریشه، رکورد A به IP سرور).</li>
                    <li>«بررسی DNS» را بزنید؛ گواهی HTTPS به‌صورت خودکار صادر می‌شود.</li>
                </ol>
            </x-ui.card>
        </aside>
    </div>
@endsection
