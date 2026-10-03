<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use App\Services\Core\Identity\EmailAuthService;
use App\Services\Core\Identity\EmailIdentity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

class NewPasswordController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(protected EmailAuthService $emailAuth) {}

    public function create(Request $request): View
    {
        return view('website.auth.reset-password', ['token' => $request->route('token')]);
    }

    public function store(Request $request): RedirectResponse
    {
        // E1: Email نرمال‌سازی و به شکل ذخیره‌شده در DB تبدیل می‌شود (Broker تطبیق دقیق دارد).
        $request->merge(['email' => EmailIdentity::canonical($request->input('email'))]);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // E2: رمز + تأیید Email + ابطال Sessionها + Audit (Core).
                $this->emailAuth->completePasswordReset($user, $password);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'این لینک بازیابی نامعتبر یا منقضی شده است.',
            ]);
        }

        return redirect($this->websiteRoute($request, 'login'))
            ->with('status', 'رمز عبور شما با موفقیت تغییر کرد. اکنون وارد شوید.');
    }
}
