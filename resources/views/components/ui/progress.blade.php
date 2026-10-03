{{--
    Progress (B3.1). <x-ui.progress :value="62" tone="warning" label="حجم مصرف‌شده" />
    value ۰..۱۰۰ (بیرون از بازه clamp می‌شود). tone: success|warning|danger — رنگ فقط حامل معنا نیست؛
    مقدار عددی همیشه کنار آن نوشته می‌شود و role=progressbar برای Screen reader دارد.
--}}
@props(['value' => 0, 'tone' => 'success', 'label' => ''])
@php $pct = max(0, min(100, (int) $value)); @endphp
<div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="{{ $label }}"
     {{ $attributes->merge(['class' => 'h-2 w-full overflow-hidden rounded-full bg-surface-2']) }}>
    <div class="h-full rounded-full bg-{{ in_array($tone, ['success', 'warning', 'danger'], true) ? $tone : 'success' }}"
         style="width: {{ $pct }}%"></div>
</div>
