<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Identity\AccountLinkResult;
use App\Services\Core\Identity\AccountLinkingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * B2.4 — جداسازی روش‌های اتصال و تعیین اولین رمز از Profile. قرارداد: ACCOUNT-LINKING-CONTRACT.md.
 *
 * Website فقط Channel است: تصمیم (Lock-out، مالکیت، Audit) در AccountLinkingService است.
 * اینجا فقط «تأیید مجدد هویت با رمز فعلی» (L5) و تبدیل نتیجه به پیام انجام می‌شود.
 * اتصال Google با GoogleAuthController::linkRedirect و اتصال Telegram با TelegramLinkController است.
 */
class AccountLinkingController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(protected AccountLinkingService $linking) {}

    public function unlinkGoogle(Request $request): RedirectResponse
    {
        if ($failure = $this->confirmPassword($request, 'google')) {
            return $failure;
        }

        $result = $this->linking->unlinkGoogle($request->user());

        return $this->respond($request, $result, 'google', 'حساب Google جدا شد.', [
            AccountLinkResult::REASON_NOT_LINKED => 'حساب Google وصل نیست.',
            AccountLinkResult::REASON_LAST_LOGIN_METHOD => 'Google تنها روش ورود شماست. ابتدا یک رمز عبور تعیین کنید.',
        ]);
    }

    public function unlinkTelegram(Request $request): RedirectResponse
    {
        if ($failure = $this->confirmPassword($request, 'telegram')) {
            return $failure;
        }

        $result = $this->linking->unlinkTelegram($request->user());

        return $this->respond($request, $result, 'telegram', 'حساب تلگرام جدا شد.', [
            AccountLinkResult::REASON_NOT_LINKED => 'تلگرام وصل نیست.',
            AccountLinkResult::REASON_LAST_LOGIN_METHOD => 'برای جدا کردن تلگرام باید یک ایمیل و یک روش ورود (رمز یا Google) داشته باشید.',
        ]);
    }

    /** D-13: اولین رمز برای کاربر Google (تغییر رمز موجود در این فاز نیست). */
    public function setPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'password.required' => 'رمز عبور را وارد کنید.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ]);

        $result = $this->linking->setFirstPassword($request->user(), $data['password'], $request->session()->getId());

        return $this->respond($request, $result, 'password', 'رمز عبور شما تعیین شد.', [
            AccountLinkResult::REASON_PASSWORD_ALREADY_SET => 'برای این حساب قبلاً رمز تعیین شده است.',
            AccountLinkResult::REASON_PROVIDER_EMAIL_NOT_VERIFIED => 'ابتدا ایمیل خود را تأیید کنید.',
        ]);
    }

    /**
     * B2.5 — تغییر رمز موجود. رمز فعلی در Core تأیید می‌شود. پس از موفقیت همه‌ی نشست‌های دیگر بسته
     * می‌شوند (Core، با حذف سطر) و `logoutOtherDevices` Hash نشست فعلی و Cookie «به‌خاطر بسپار» همین
     * دستگاه را به‌روز می‌کند؛ با Driverهای غیر-database همین مقایسه‌ی Hash در AuthenticateSession
     * دستگاه‌های دیگر را می‌بندد.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'existing_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'existing_password.required' => 'رمز عبور فعلی را وارد کنید.',
            'password.required' => 'رمز عبور جدید را وارد کنید.',
            'password.confirmed' => 'تکرار رمز عبور مطابقت ندارد.',
        ]);

        $result = $this->linking->changePassword(
            $request->user(),
            $data['existing_password'],
            $data['password'],
            $request->session()->getId(),
        );

        if ($result->isRejected()) {
            $field = $result->reason === AccountLinkResult::REASON_PASSWORD_UNCHANGED ? 'password' : 'existing_password';
            $message = match ($result->reason) {
                AccountLinkResult::REASON_WRONG_PASSWORD => 'رمز عبور فعلی درست نیست.',
                AccountLinkResult::REASON_PASSWORD_UNCHANGED => 'رمز جدید باید با رمز فعلی فرق داشته باشد.',
                default => 'رمز عبور تغییر نکرد.',
            };

            return redirect($this->websiteRoute($request, 'identity.profile.show'))->withErrors([$field => $message]);
        }

        Auth::guard('web')->logoutOtherDevices($data['password']);

        return redirect($this->websiteRoute($request, 'identity.profile.show'))
            ->with('status', 'رمز عبور تغییر کرد و از دستگاه‌های دیگر خارج شدید.');
    }

    /**
     * L5: Unlink عملیات حساس است. اگر کاربر رمز دارد باید رمز فعلی را بدهد (Session دزدیده‌شده
     * به‌تنهایی کافی نباشد). کاربر بدون رمز (فقط Google) چیزی برای تأیید ندارد.
     */
    protected function confirmPassword(Request $request, string $errorKey): ?RedirectResponse
    {
        $user = $request->user();

        if (! $this->linking->hasPassword($user)) {
            return null;
        }

        $request->validate(['current_password' => ['required', 'string']], [
            'current_password.required' => 'برای تأیید، رمز عبور فعلی را وارد کنید.',
        ]);

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            return redirect($this->websiteRoute($request, 'identity.profile.show'))
                ->withErrors([$errorKey => 'رمز عبور فعلی درست نیست.']);
        }

        return null;
    }

    /** @param array<string,string> $messages پیام هر دلیل رد */
    protected function respond(Request $request, AccountLinkResult $result, string $errorKey, string $success, array $messages): RedirectResponse
    {
        $back = redirect($this->websiteRoute($request, 'identity.profile.show'));

        if ($result->isRejected()) {
            return $back->withErrors([$errorKey => $messages[$result->reason] ?? 'عملیات انجام نشد.']);
        }

        return $back->with('status', $success);
    }
}
