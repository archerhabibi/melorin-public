<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Identity\AccountLinkingService;
use App\Services\Core\Identity\SessionSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * B2.5 — پایان نشست‌های فعال از Profile (SESSION-SECURITY-CONTRACT.md §S5).
 *
 * Website فقط Channel است: تصمیم و حذف در SessionSecurityService است. اینجا فقط «تأیید مجدد هویت با
 * رمز فعلی» (مثل L5 در B2.4) و تبدیل نتیجه به پیام انجام می‌شود. نشست فعلی هرگز از این مسیر بسته نمی‌شود؛
 * برای آن «خروج» (POST /sign-out) وجود دارد.
 */
class SessionController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected SessionSecurityService $sessions,
        protected AccountLinkingService $linking,
    ) {}

    public function revokeOthers(Request $request): RedirectResponse
    {
        if ($failure = $this->confirmPassword($request)) {
            return $failure;
        }

        $count = $this->sessions->revokeOthers($request->user(), $request->session()->getId());

        return $this->back($request)->with('status', $count > 0
            ? 'از همه‌ی دستگاه‌های دیگر خارج شدید.'
            : 'نشست فعال دیگری وجود نداشت.');
    }

    public function revoke(Request $request): RedirectResponse
    {
        if ($failure = $this->confirmPassword($request)) {
            return $failure;
        }

        $ok = $this->sessions->revokeByHandle(
            $request->user(),
            (string) $request->input('session'),
            $request->session()->getId(),
        );

        return $ok
            ? $this->back($request)->with('status', 'نشست انتخاب‌شده بسته شد.')
            : $this->back($request)->withErrors(['sessions' => 'این نشست پیدا نشد یا قبلاً بسته شده است.']);
    }

    /** کاربر دارای رمز باید رمز فعلی بدهد؛ کاربر فقط-Google چیزی برای تأیید ندارد (هم‌راستا با L5). */
    protected function confirmPassword(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        if (! $this->linking->hasPassword($user)) {
            return null;
        }

        $given = $request->input('current_password');

        if (! is_string($given) || $given === '') {
            return $this->back($request)->withErrors(['sessions' => 'برای تأیید، رمز عبور فعلی را وارد کنید.']);
        }

        if (! Hash::check($given, (string) $user->password)) {
            return $this->back($request)->withErrors(['sessions' => 'رمز عبور فعلی درست نیست.']);
        }

        return null;
    }

    protected function back(Request $request): RedirectResponse
    {
        return redirect($this->websiteRoute($request, 'identity.profile.show'));
    }
}
