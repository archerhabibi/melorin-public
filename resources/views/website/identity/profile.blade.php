@extends('website.layouts.app')

@section('title', 'پروفایل')

@section('content')
    @include('website.account._nav')

    @php
        $isReseller = $store->isReseller();
        $route = fn (string $name) => $isReseller ? route('website.store.'.$name, $store->reseller->slug) : route('website.'.$name);
        // رمز فعلی فقط وقتی لازم است که کاربر رمز دارد (L5).
        $needsPassword = $hasPassword;
    @endphp

    <x-ui.card class="max-w-xl">
        <h1 class="page-title">پروفایل</h1>
        <dl class="mt-4 space-y-2 text-sm text-muted">
            <div class="flex justify-between"><dt>نام</dt><dd>{{ $user->full_name }}</dd></div>
            <div class="flex justify-between">
                <dt>ایمیل</dt>
                <dd class="flex items-center gap-2">
                    {{ $user->email }}
                    @if($user->email)
                        @if($user->hasVerifiedEmail())
                            <x-ui.badge tone="success">تأییدشده</x-ui.badge>
                        @else
                            <x-ui.badge tone="warning">تأییدنشده</x-ui.badge>
                        @endif
                    @endif
                </dd>
            </div>
        </dl>
    </x-ui.card>

    {{-- B2.4 — روش‌های ورود و حساب‌های متصل. تصمیم‌ها در Core (AccountLinkingService)؛ اینجا فقط نمایش. --}}
    <x-ui.card class="mt-4 max-w-xl">
        <h2 class="text-base font-bold">روش‌های ورود</h2>
        <p class="mt-1 text-sm text-muted">
            برای جلوگیری از قفل‌شدن حساب، همیشه باید حداقل یک روش ورود (رمز عبور یا Google) داشته باشید.
        </p>

        {{-- رمز عبور --}}
        <div class="mt-5 border-t border-border pt-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold">رمز عبور</h3>
                @if($hasPassword)
                    <x-ui.badge tone="success">تعیین‌شده</x-ui.badge>
                @else
                    <x-ui.badge tone="warning">تعیین‌نشده</x-ui.badge>
                @endif
            </div>

            @if(! $hasPassword)
                @error('password')<x-ui.alert type="danger" class="mt-3">{{ $message }}</x-ui.alert>@enderror
            @endif

            @if(! $hasPassword)
                <p class="mt-2 text-sm text-muted">
                    با Google وارد شده‌اید و رمزی ندارید. با تعیین رمز می‌توانید بعداً Google را در صورت نیاز جدا کنید.
                </p>
                @if($user->hasVerifiedEmail())
                    <form method="POST" action="{{ $route('identity.password.set') }}" class="mt-3 space-y-3">
                        @csrf
                        <x-ui.field name="password" type="password" label="رمز عبور جدید" required autocomplete="new-password" />
                        <x-ui.field name="password_confirmation" type="password" label="تکرار رمز عبور" required autocomplete="new-password" />
                        <x-ui.button type="submit">تعیین رمز عبور</x-ui.button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-warning">برای تعیین رمز ابتدا ایمیل خود را تأیید کنید.</p>
                @endif
            @else
                {{-- B2.5: تغییر رمز. پس از تغییر، از همه‌ی دستگاه‌های دیگر خارج می‌شوید. --}}
                <form method="POST" action="{{ $route('identity.password.update') }}" class="mt-3 space-y-3">
                    @csrf
                    <x-ui.field name="existing_password" type="password" label="رمز عبور فعلی" required autocomplete="current-password" />
                    <x-ui.field name="password" type="password" label="رمز عبور جدید" required autocomplete="new-password" />
                    <x-ui.field name="password_confirmation" type="password" label="تکرار رمز عبور جدید" required autocomplete="new-password" />
                    <p class="text-xs text-muted">با تغییر رمز، از همه‌ی دستگاه‌های دیگر خارج می‌شوید؛ همین دستگاه وارد می‌ماند.</p>
                    <x-ui.button type="submit" variant="secondary">تغییر رمز عبور</x-ui.button>
                </form>
            @endif
        </div>

        {{-- Google --}}
        @if($googleEnabled || $googleIdentity)
            <div class="mt-5 border-t border-border pt-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold">Google</h3>
                    @if($googleIdentity)
                        <x-ui.badge tone="success">متصل</x-ui.badge>
                    @else
                        <x-ui.badge>متصل نیست</x-ui.badge>
                    @endif
                </div>

                @error('google')<x-ui.alert type="danger" class="mt-3">{{ $message }}</x-ui.alert>@enderror

                @if($googleIdentity)
                    @if($googleIdentity->provider_email)
                        <p class="mt-2 text-sm text-muted">حساب متصل: <span dir="ltr">{{ $googleIdentity->provider_email }}</span></p>
                    @endif

                    @if($hasPassword)
                        <form method="POST" action="{{ $route('identity.google.unlink') }}" class="mt-3 space-y-3">
                            @csrf
                            <x-ui.field name="current_password" type="password" label="رمز عبور فعلی (برای تأیید)" required autocomplete="current-password" />
                            <x-ui.button type="submit" variant="secondary">جدا کردن Google</x-ui.button>
                        </form>
                    @else
                        <p class="mt-2 text-sm text-muted">Google تنها روش ورود شماست؛ برای جدا کردن آن ابتدا رمز عبور تعیین کنید.</p>
                    @endif
                @elseif($googleEnabled)
                    <p class="mt-2 text-sm text-muted">با اتصال Google می‌توانید با یک کلیک وارد شوید.</p>
                    <div class="mt-3">
                        <x-ui.button :href="$route('identity.google.link')" variant="secondary" rel="nofollow">اتصال حساب Google</x-ui.button>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.card>

    {{-- B2.5 — نشست‌های فعال. تصمیم‌ها در Core (SessionSecurityService)؛ شناسه‌ی نمایش‌داده‌شده HMAC است، نه Session ID. --}}
    @if($sessionsSupported && $activeSessions->isNotEmpty())
        <x-ui.card class="mt-4 max-w-xl">
            <h2 class="text-base font-bold">دستگاه‌ها و نشست‌های فعال</h2>
            <p class="mt-1 text-sm text-muted">
                اگر نشستی را نمی‌شناسید، آن را ببندید و رمز عبور خود را تغییر دهید.
            </p>

            @error('sessions')<x-ui.alert type="danger" class="mt-3">{{ $message }}</x-ui.alert>@enderror

            <form method="POST" action="{{ $route('identity.sessions.revoke-others') }}" class="mt-4 space-y-4">
                @csrf
                <ul class="divide-y divide-border">
                    @foreach($activeSessions as $session)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0 text-sm">
                                <div class="flex items-center gap-2 font-bold">
                                    {{ \App\Channels\Website\Support\DeviceLabel::describe($session->userAgent) }}
                                    @if($session->isCurrent)
                                        <x-ui.badge tone="success">این دستگاه</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-1 text-xs text-muted">
                                    <span dir="ltr">{{ \App\Channels\Website\Support\DeviceLabel::maskIp($session->ip) }}</span>
                                    · آخرین فعالیت: {{ $session->lastActivity->format('Y/m/d H:i') }}
                                </div>
                            </div>
                            @unless($session->isCurrent)
                                <x-ui.button type="submit" variant="secondary" size="sm"
                                             name="session" value="{{ $session->handle }}"
                                             formaction="{{ $route('identity.sessions.revoke') }}">
                                    بستن
                                </x-ui.button>
                            @endunless
                        </li>
                    @endforeach
                </ul>

                @if($needsPassword)
                    <x-ui.field name="current_password" type="password" label="رمز عبور فعلی (برای تأیید)" autocomplete="current-password" />
                @endif

                @if($activeSessions->count() > 1)
                    <x-ui.button type="submit" variant="secondary">خروج از همه‌ی دستگاه‌های دیگر</x-ui.button>
                @endif
            </form>
        </x-ui.card>
    @endif

    {{--
        اتصال Telegram (Master G10) — ویجت رسمی تلگرام؛ TelegramLinkController
        امضا را واقعاً با bot_token تأیید می‌کند. فقط Main Context.
    --}}
    @if($telegramBotUsername)
        <x-ui.card class="mt-4 max-w-xl">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-bold">اتصال تلگرام</h2>
                @if($user->telegram_id)
                    <x-ui.badge tone="success">متصل</x-ui.badge>
                @else
                    <x-ui.badge>متصل نیست</x-ui.badge>
                @endif
            </div>

            @error('telegram')<x-ui.alert type="danger" class="mt-3">{{ $message }}</x-ui.alert>@enderror

            @if($user->telegram_id)
                <p class="mt-3 text-sm text-success">حساب تلگرام شما متصل است.</p>

                @if($user->email && ($hasPassword || $googleIdentity))
                    <p class="mt-2 text-xs text-muted">
                        با جدا کردن تلگرام، ربات دیگر این حساب را نمی‌شناسد و با /start یک حساب جدید و جدا (با کیف‌پول جدا) می‌سازد؛ موجودی فعلی در حساب وب می‌ماند.
                    </p>
                    <form method="POST" action="{{ $route('identity.telegram.unlink') }}" class="mt-3 space-y-3">
                        @csrf
                        @if($needsPassword)
                            <x-ui.field name="current_password" type="password" label="رمز عبور فعلی (برای تأیید)" required autocomplete="current-password" />
                        @endif
                        <x-ui.button type="submit" variant="secondary">جدا کردن تلگرام</x-ui.button>
                    </form>
                @else
                    <p class="mt-2 text-xs text-muted">این حساب فقط با تلگرام شناخته می‌شود و قابل جدا کردن نیست.</p>
                @endif
            @else
                <p class="mt-2 text-sm text-muted">
                    با اتصال تلگرام، هم می‌توانید سریع‌تر وارد شوید و هم پیام‌های سفارش را در ربات دریافت کنید.
                </p>
                <div class="mt-4">
                    <script async src="https://telegram.org/js/telegram-widget.js?22"
                            data-telegram-login="{{ $telegramBotUsername }}"
                            data-size="large"
                            data-auth-url="{{ route('website.identity.telegram.callback') }}?state={{ $telegramLinkState }}"
                            data-request-access="write"></script>
                </div>
            @endif
        </x-ui.card>
    @endif
@endsection
