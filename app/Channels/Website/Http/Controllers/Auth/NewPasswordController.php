<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

class NewPasswordController
{
    use ResolvesWebsiteRouteNames;

    public function create(Request $request): View
    {
        return view('website.auth.reset-password', ['token' => $request->route('token')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

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
