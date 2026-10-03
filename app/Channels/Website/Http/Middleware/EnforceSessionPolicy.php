<?php

namespace App\Channels\Website\Http\Middleware;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * B2.5 — سیاست نشست برای کاربر واردشده‌ی Website (SESSION-SECURITY-CONTRACT.md §S3/S4).
 *
 * فقط Guard «web» را می‌بیند و برای بازدیدکننده‌ی ناشناس هیچ کاری (و هیچ Query ای) نمی‌کند.
 *
 *  - S3: کاربری که پس از ورود غیرفعال/مسدود شد، نشست زنده‌اش را از دست می‌دهد (قبلاً فقط «ورود» جدید
 *    بسته می‌شد و نشست موجود تا انقضای idle کار می‌کرد).
 *  - S4: حداکثر عمر مطلق نشست (`session.absolute_lifetime`). Idle Timeout لاراول لغزان است؛ بدون سقف
 *    مطلق، نشستِ دزدیده‌شده‌ای که مدام استفاده شود هرگز نمی‌میرد. نشستِ «مرا به‌خاطر بسپار» عمداً
 *    مستثناست (کاربر صراحتاً ماندگاری خواسته؛ سقفش `auth.guards.web.remember` است).
 */
class EnforceSessionPolicy
{
    use ResolvesWebsiteRouteNames;

    public const STARTED_AT = 'auth.started_at';

    public const REMEMBERED = 'auth.remembered';

    public function __construct(protected AuditService $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if (! $request->hasSession() || ! $guard->check()) {
            return $next($request);
        }

        $user = $guard->user();
        $session = $request->session();

        if ($user->status !== 'active') {
            return $this->endSession($request, 'user_not_active');
        }

        // نشستی که پیش از B2.5 ساخته شده یا با actingAs آمده مُهر ندارد؛ از همین لحظه حساب می‌شود.
        if (! $session->has(self::STARTED_AT)) {
            $session->put([
                self::STARTED_AT => now()->getTimestamp(),
                self::REMEMBERED => $guard->viaRemember(),
            ]);

            return $next($request);
        }

        if ($this->absoluteTimeoutReached($request)) {
            return $this->endSession($request, 'absolute_timeout');
        }

        return $next($request);
    }

    protected function absoluteTimeoutReached(Request $request): bool
    {
        $max = (int) config('session.absolute_lifetime', 0);
        $session = $request->session();

        if ($max <= 0 || $session->get(self::REMEMBERED) === true) {
            return false;
        }

        return now()->getTimestamp() - (int) $session->get(self::STARTED_AT) > $max * 60;
    }

    protected function endSession(Request $request, string $reason): Response
    {
        $user = Auth::guard('web')->user();

        // logoutCurrentDevice (نه logout): logout() remember_token را می‌چرخاند و Cookie «به‌خاطر بسپار»
        // همه‌ی دستگاه‌های دیگر کاربر را هم می‌سوزاند؛ اینجا فقط همین نشست باید بسته شود.
        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->audit->record('identity.session_terminated', $user, after: ['reason' => $reason], actor: $user);

        return redirect($this->websiteRoute($request, 'login'))
            ->withErrors(['email' => 'نشست شما به پایان رسید. لطفاً دوباره وارد شوید.']);
    }
}
