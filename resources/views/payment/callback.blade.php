<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نتیجه پرداخت</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #0f172a; color: #e2e8f0;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 1rem; max-width: 30rem;
                text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,.4); }
        .icon { font-size: 3.5rem; margin-bottom: 1rem; }
        .ok { color: #4ade80; }
        .fail { color: #f87171; }
        p { line-height: 2; }
        .amount { margin-top: 1rem; font-size: .9rem; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon {{ $success ? 'ok' : 'fail' }}">{{ $success ? '✅' : '❌' }}</div>
        <p>{{ $message }}</p>
        @isset($amount)
            <div class="amount">مبلغ: {{ number_format((float) $amount) }} تومان</div>
        @endisset
    </div>
</body>
</html>
