{{-- B3.2 — برچسبِ وضعیتِ سرویس. وضعیت از Account::displayState() می‌آید (مرجع واحد؛ شرط در View نیست). --}}
@php
    $map = [
        'active' => ['فعال', 'success'],
        'expiring' => ['رو به انقضا', 'warning'],
        'expired' => ['منقضی‌شده', 'danger'],
        'disabled' => ['غیرفعال', 'warning'],
        'suspended' => ['تعلیق‌شده', 'warning'],
    ];
    [$stateLabel, $stateTone] = $map[$state] ?? [$state, 'neutral'];
@endphp
<x-ui.badge :tone="$stateTone">{{ $stateLabel }}</x-ui.badge>
