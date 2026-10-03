{{--
    B2.3 — یادآور «ادامه‌ی خرید» روی صفحه‌ی Login/Register وقتی نشست Guest فعال است.
    ورودی: $guestPending (GuestCheckout|null) با رابطه‌ی product.
    فقط نمایش است؛ هیچ داده‌ای نمی‌سازد و ایمیل Guest را نشان نمی‌دهد.
--}}
@if(! empty($guestPending) && $guestPending->product)
    <x-ui.alert type="info" class="mb-4">
        پس از ورود یا ثبت‌نام، خرید «{{ $guestPending->product->name }}» از همین‌جا ادامه پیدا می‌کند.
    </x-ui.alert>
@endif
