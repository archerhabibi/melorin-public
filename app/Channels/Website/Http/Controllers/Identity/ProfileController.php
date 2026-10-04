<?php

namespace App\Channels\Website\Http\Controllers\Identity;

use App\Channels\Website\Services\WebsiteProfileFacade;
use App\Channels\Website\Support\GoogleOAuthClient;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Services\Core\Customer\ProfileCenterException;
use App\Services\Core\Customer\ProfileCenterService;
use App\Services\Core\Identity\AccountLinkingService;
use App\Services\Core\Identity\SessionSecurityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * بازتعریف `/complete-profile`.
 *
 * قبلاً برای «User بدون رمز» (ساخته‌شده از Guest، مدل DEPRECATED) رمز
 * عبور می‌گرفت. در مدل جدید هر User با Register و رمز ساخته می‌شود، پس آن
 * قابلیت حذف شد. این صفحه فقط «پروفایل / اتصال Telegram» است (Master G10):
 * User احراز‌شده Telegram خودش را با ویجت رسمی وصل می‌کند؛ اعتبارسنجی HMAC و
 * ضد-CSRF (state یک‌بارمصرف) در TelegramLinkController است.
 *
 * B2.4: همین صفحه «روش‌های ورود و حساب‌های متصل» (Google، Telegram، رمز) را نشان می‌دهد.
 * B3.5 (Profile Center): نمای کلی حساب + ویرایش نام و موبایل. منطق (اعتبارسنجی، یکتایی، Audit) در Core است
 * (`ProfileCenterService`)؛ این‌جا فقط تحویل ورودی و نگاشت خطا. قرارداد: `CUSTOMER-PROFILE-CONTRACT.md`.
 */
class ProfileController
{
    use ResolvesWebsiteRouteNames;

    public function __construct(
        protected WebsiteProfileFacade $profile,
        protected AccountLinkingService $linking,
        protected GoogleOAuthClient $google,
        protected SessionSecurityService $sessions,
    ) {}

    public function show(Request $request, StoreContext $store): View
    {
        // state یک‌بارمصرف Session-bound — ضد Login/Link CSRF.
        $state = bin2hex(random_bytes(16));
        $request->session()->put('telegram_link_state', $state);

        $user = $request->user();

        return view('website.identity.profile', [
            'user' => $user,
            // B3.5: خلاصه‌ی Profile (فقط خواندن؛ هیچ رکوردی ساخته نمی‌شود).
            'overview' => $this->profile->overview($user, $store),
            'nameMax' => ProfileCenterService::NAME_MAX,
            // B2.4: وضعیت روش‌های ورود/اتصال (فقط خواندن؛ تصمیم‌ها در AccountLinkingService).
            'hasPassword' => $this->linking->hasPassword($user),
            'googleIdentity' => $this->linking->googleIdentity($user),
            'googleEnabled' => $this->google->enabled(),
            // B2.5: نشست‌های فعال (فقط با Session Driver = database؛ در غیر این صورت کارت نمایش داده نمی‌شود).
            'sessionsSupported' => $this->sessions->supported(),
            'activeSessions' => $this->sessions->activeFor($user, $request->session()->getId()),
            'store' => $store,
            'telegramBotUsername' => $store->isReseller() ? null : config('telegram.bots.main.username'),
            'telegramLinkState' => $state,
        ]);
    }

    /**
     * B3.5 — ذخیره‌ی نام و موبایل. فقط دو فیلد فهرست‌سفید؛ هر فیلد دیگر در درخواست نادیده گرفته می‌شود (ایمیل،
     * وضعیت، telegram_id و ... هرگز از این مسیر عوض نمی‌شوند). قواعد در Core.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // فقط نوع/اندازه‌ی خام؛ قواعد واقعی (طول، حرف، فرمت و یکتایی) یک‌جا در Core است تا ربات هم همان را بگیرد.
            'full_name' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:60'],
        ], [
            'full_name.required' => 'نام را وارد کنید.',
            'full_name.string' => 'نام واردشده معتبر نیست.',
            'phone.string' => 'شماره‌ی موبایل معتبر نیست.',
        ]);

        try {
            $changed = $this->profile->update($request->user(), [
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
            ]);
        } catch (ProfileCenterException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect($this->websiteRoute($request, 'identity.profile.show'))
            ->with('status', $changed === [] ? 'اطلاعات شما بدون تغییر است.' : 'اطلاعات پروفایل ذخیره شد.');
    }
}
