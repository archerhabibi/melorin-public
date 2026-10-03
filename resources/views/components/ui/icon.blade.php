{{--
    Icon System (B1.1): آیکون‌های inline-SVG، سبک outline، stroke=currentColor، شبکه ۲۴.
    بدون وابستگی خارجی (CSP: img/font فقط self). استفاده: <x-ui.icon name="wallet" class="h-5 w-5" />
    آیکون جدید = یک ردیف به آرایه‌ی زیر.
--}}
@props(['name', 'size' => 20])
@php
    $paths = [
        'home'     => 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        'wallet'   => 'M3 7a2 2 0 012-2h13v4M3 7v10a2 2 0 002 2h14a1 1 0 001-1V9a1 1 0 00-1-1H5a2 2 0 01-2-1zM16 14h2',
        'orders'   => 'M6 3h12l1 4H5l1-4zM5 7v13h14V7M9 11h6',
        'server'   => 'M4 5h16v6H4zM4 13h16v6H4zM8 8h.01M8 16h.01',
        'gift'     => 'M4 11h16v9H4zM3 7h18v4H3zM12 7v13M12 7c-2 0-4-1-4-3s3-2 4 1c1-3 4-3 4-1s-2 3-4 3',
        'user'     => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
        'users'    => 'M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM2 20a7 7 0 0114 0M17 4.5a3.5 3.5 0 010 6.5M18 14a7 7 0 013 6',
        'settings' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-4-2 1a7 7 0 00-2-1l-.5-2h-5L9 7a7 7 0 00-2 1L5 7l-2 4 2 1a7 7 0 000 2l-2 1 2 4 2-1a7 7 0 002 1l.5 2h5L15 19a7 7 0 002-1l2 1 2-4-2-1a7 7 0 000-2z',
        'logout'   => 'M10 4H5v16h5M15 8l4 4-4 4M19 12H9',
        'login'    => 'M14 4h5v16h-5M9 8l-4 4 4 4M5 12h10',
        'plus'     => 'M12 5v14M5 12h14',
        'check'    => 'M5 13l4 4L19 7',
        'x'        => 'M6 6l12 12M18 6L6 18',
        'copy'     => 'M9 9h10v11H9zM5 15V4h10',
        'link'     => 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
        'refresh'  => 'M20 11a8 8 0 00-14-4L4 9M4 4v5h5M4 13a8 8 0 0014 4l2-2M20 20v-5h-5',
        'alert'    => 'M12 9v4M12 17h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
        'info'     => 'M12 8h.01M11 12h1v5h1M12 21a9 9 0 100-18 9 9 0 000 18z',
        'mail'     => 'M3 6h18v12H3zM3 7l9 7 9-7',
        'lock'     => 'M6 11h12v9H6zM8 11V8a4 4 0 018 0v3',
        'ticket'   => 'M4 8a2 2 0 002-2h12a2 2 0 002 2v2a2 2 0 000 4v2a2 2 0 00-2 2H6a2 2 0 00-2-2v-2a2 2 0 000-4V8z',
        'sun'      => 'M12 16a4 4 0 100-8 4 4 0 000 8zM12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5',
        'moon'     => 'M20 14.5A8 8 0 019.5 4 8 8 0 1020 14.5z',
        'chevron'  => 'M9 6l6 6-6 6',
    ];
    $d = $paths[$name] ?? null;
@endphp
@if($d)
    <svg {{ $attributes->merge(['class' => 'shrink-0']) }}
         width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $d }}"/>
    </svg>
@endif
