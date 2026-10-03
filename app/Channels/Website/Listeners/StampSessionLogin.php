<?php

namespace App\Channels\Website\Listeners;

use App\Channels\Website\Http\Middleware\EnforceSessionPolicy;
use Illuminate\Auth\Events\Login;

/**
 * B2.5 — لحظه‌ی شروع نشست احراز‌شده را در Session ثبت می‌کند (پایه‌ی سقف مطلق عمر نشست).
 *
 * روی رویداد Login لاراول سوار است تا همه‌ی مسیرهای ورود (Email، ثبت‌نام، Google، ورود با Cookie
 * «به‌خاطر بسپار») بدون دست‌زدن به Controllerها پوشش داده شوند. `Session::regenerate()` داده را نگه
 * می‌دارد و `invalidate()` پس از خروج همه را پاک می‌کند، پس مُهر قدیمی به ورود بعدی نشت نمی‌کند.
 */
class StampSessionLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put([
            EnforceSessionPolicy::STARTED_AT => now()->getTimestamp(),
            EnforceSessionPolicy::REMEMBERED => (bool) $event->remember,
        ]);
    }
}
