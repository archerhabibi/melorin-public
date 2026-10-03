{{--
    B3.2 — نوار مصرف حجم (مشترک بین فهرست و جزئیات). عدد همیشه کنار نوار نوشته می‌شود (رنگ تنها حامل معنا نیست).
    ورودی: $account (Account). لحن: Account::trafficTone() — همان آستانه‌ی ۹۰٪ داشبورد.
--}}
@php
    $usagePercent = $account->trafficUsagePercent();
    $usedGb = $account->usedTrafficGb();
    $remainingGb = $account->remainingTrafficGb();
@endphp
<div>
    <div class="flex justify-between text-xs text-muted mb-1.5 gap-2">
        <span>مصرف حجم</span>
        <span class="tabular">
            @if($usagePercent === null)
                {{ number_format($usedGb, 1) }} گیگابایت مصرف‌شده · نامحدود
            @else
                {{ $usagePercent }}٪ · {{ number_format($remainingGb, 1) }} از {{ number_format((float) $account->traffic_gb, 1) }} گیگابایت باقی‌مانده
            @endif
        </span>
    </div>
    @if($usagePercent !== null)
        <x-ui.progress :value="$usagePercent" :tone="$account->trafficTone()" label="حجم مصرف‌شده" />
    @endif
</div>
