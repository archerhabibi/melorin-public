{{--
    B6.2 — بخش مشترک <head> برای برندینگ فروشگاه (Layout وب و صفحه‌ی نتیجه‌ی پرداخت): تنها جایی که متغیرهای رنگ
    Brand چاپ می‌شوند. ورودی: $brand (StoreBrand). همه‌ی مقدارها hex شش‌رقمیِ اعتبارسنجی‌شده‌اند.
      --brand            رنگ پس‌زمینه‌ی دکمه‌ها (همان انتخاب نماینده)
      --brand-contrast   رنگ متن روی --brand
      --brand-text       رنگ «متن/لینک» برند روی سطح روشن (contrast ≥ ۴.۵)
      --brand-text-dark  همان برای سطح تیره
--}}
<style>:root { --brand: {{ $brand->color }}; --brand-contrast: {{ $brand->onColor() }}; --brand-text: {{ $brand->textColor() }}; --brand-text-dark: {{ $brand->darkTextColor() }}; }</style>
@if($brand->faviconUrl)
    <link rel="icon" href="{{ $brand->faviconUrl }}">
@endif
