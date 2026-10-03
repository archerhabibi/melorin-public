<?php

namespace App\Channels\Website\Http\Controllers\Auth;

use App\Channels\Website\Support\GoogleOAuthClient;
use App\Channels\Website\Support\GoogleOAuthException;
use App\Channels\Website\Support\GuestCheckoutContinuation;
use App\Channels\Website\Support\ResolvesWebsiteRouteNames;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Identity\AccountLinkResult;
use App\Services\Core\Identity\AccountLinkingService;
use App\Services\Core\Identity\ExternalIdentityResult;
use App\Services\Core\Identity\ExternalIdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google Sign-In (B2.1). قرارداد: docs/canonical/GOOGLE-SIGNIN-CONTRACT.md.
 *
 * Website فقط Channel است: پروتکل در GoogleOAuthClient، تصمیم Identity در Core
 * (ExternalIdentityService). این کنترلر فقط Session/Redirect/پیام را هماهنگ می‌کند.
 *
 * Redirect URI گوگل «ثابت» است و فقط روی Context اصلی وجود دارد؛ بنابراین مقصد برگشت
 * (فروشگاه نماینده / ادامه‌ی خرید Guest / url.intended) در لحظه‌ی شروع، سمت سرور و داخل
 * Session نگه داشته می‌شود — هیچ آدرس بازگشتی از Query/Client پذیرفته نمی‌شود (ضد Open Redirect).
 *
 * G6: ورود با Google همان خرید Pending را ادامه می‌دهد؛ User/CustomerAccount/Purchase ای از Guest
 * ساخته نمی‌شود.
 *
 * G21 (تصمیم صاحب پروژه — استثنای R7): ورود موفق با Google، CustomerAccount همان User را در
 * فروشگاهی که ورود از آن شروع شده می‌سازد (اگر نبود). چون callback همیشه روی Context اصلی است،
 * فروشگاه مبدأ در لحظه‌ی شروع سمت سرور در Session نگه داشته می‌شود (نه از Query/Client) و
 * در callback بازسازی می‌شود. تصمیم و ساخت در Core است (ExternalIdentityService).
 */
class GoogleAuthController
{
    use ResolvesWebsiteRouteNames;

    public const SESSION_KEY = 'google_oauth';
    public const TTL_SECONDS = 600;

    public function __construct(
        protected GoogleOAuthClient $google,
        protected ExternalIdentityService $identity,
        protected GuestCheckoutContinuation $guestContinuation,
        protected AccountLinkingService $linking,
    ) {}

    /**
     * B2.4 — شروع «اتصال Google» برای User واردشده (از Profile). همان Redirect URI ثابت و همان
     * Session؛ تفاوت فقط `mode = link` و `user_id` سمت سرور است. مقصد برگشت و خطاها همیشه
     * Profile فروشگاه مبدأ است (هیچ URL از Client پذیرفته نمی‌شود).
     */
    public function linkRedirect(Request $request, StoreContext $store): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $profileUrl = $this->websiteRoute($request, 'identity.profile.show');

        $request->session()->put(self::SESSION_KEY, [
            'mode' => 'link',
            'user_id' => $request->user()->id,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'created_at' => time(),
            'store' => $store->toArray(),
            'login_url' => $profileUrl,
            'continue_url' => $profileUrl,
        ]);

        return redirect()->away($this->google->authorizationUrl($state, $nonce, $verifier));
    }

    public function redirect(Request $request, StoreContext $store): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);

        // Referral: ?ref فقط اگر به User موجود اشاره کند (مثل Register).
        $ref = $request->integer('ref');

        if ($ref && User::query()->whereKey($ref)->exists()) {
            $request->session()->put('referrer_id_candidate', $ref);
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64); // RFC 7636: 43–128 کاراکتر Unreserved

        $intended = $this->internalUrlOrNull($request, $request->session()->get('url.intended'));

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'created_at' => time(),
            'store' => $store->toArray(), // G21: فروشگاه مبدأ؛ فقط سمت سرور
            'login_url' => $this->websiteRoute($request, 'login'),
            'continue_url' => $intended
                ?? $this->guestContinuation->checkoutUrl($request, $store)
                ?? $this->websiteRoute($request, 'home'),
        ]);

        return redirect()->away($this->google->authorizationUrl($state, $nonce, $verifier));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);

        // یک‌بارمصرف: pull. هر Replay یا Session دیگری با state خالی روبه‌رو می‌شود.
        $ctx = $request->session()->pull(self::SESSION_KEY);
        $loginUrl = is_array($ctx) && isset($ctx['login_url']) ? $ctx['login_url'] : route('website.login');
        $isLink = is_array($ctx) && ($ctx['mode'] ?? null) === 'link';

        // کاربر واردشده فقط برای «اتصال» به این مسیر می‌آید (قبلاً Middleware `guest` این را می‌بست).
        if ($request->user() && ! $isLink) {
            return redirect($this->websiteRoute($request, 'home'));
        }

        // نشست اتصال بدون کاربر واردشده (Session منقضی/خروج وسط راه) ⇒ شکست عمومی، بدون هیچ اثر.
        if ($isLink && ! $request->user()) {
            return $this->fail(route('website.login'), 'درخواست اتصال Google منقضی شده است. دوباره وارد شوید.');
        }

        // نشست اتصال برای کاربر دیگری ساخته شده بود (تعویض حساب وسط راه) ⇒ رد.
        if ($isLink && (int) ($ctx['user_id'] ?? 0) !== (int) $request->user()->id) {
            return $this->fail($loginUrl, 'درخواست اتصال Google نامعتبر بود. دوباره تلاش کنید.');
        }

        if (! is_array($ctx) || (time() - (int) ($ctx['created_at'] ?? 0)) > self::TTL_SECONDS) {
            return $this->fail($loginUrl, 'درخواست ورود با Google منقضی شده یا نامعتبر بود. دوباره تلاش کنید.');
        }

        if ($request->query('error')) {
            return $this->fail($loginUrl, 'ورود با Google لغو شد.');
        }

        if (! hash_equals((string) $ctx['state'], (string) $request->query('state', ''))) {
            return $this->fail($loginUrl, 'درخواست ورود با Google نامعتبر بود. دوباره تلاش کنید.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail($loginUrl, 'ورود با Google انجام نشد. دوباره تلاش کنید.');
        }

        try {
            $profile = $this->google->fetchProfile($code, (string) $ctx['verifier'], (string) $ctx['nonce']);
        } catch (GoogleOAuthException $e) {
            // فقط کد خطا؛ نه توکن، نه Email.
            Log::warning('google_login_failed', ['reason' => $e->getMessage()]);

            return $this->fail($loginUrl, 'ورود با Google انجام نشد. دوباره تلاش کنید.');
        }

        if ($isLink) {
            return $this->finishLink($request, $ctx, $profile);
        }

        $result = $this->identity->resolve(
            provider: 'google',
            subject: $profile->subject,
            email: $profile->email,
            emailVerified: $profile->emailVerified,
            name: $profile->name,
            referrerId: (int) $request->session()->get('referrer_id_candidate') ?: null,
            autoLink: (bool) config('services.google.auto_link', true),
            store: $this->originStore($ctx),
        );

        if ($result->isRejected()) {
            return $this->fail($loginUrl, $this->messageFor($result->reason));
        }

        Auth::guard('web')->login($result->user);
        $request->session()->regenerate(); // ضد Session Fixation (Website §6)

        if ($result->outcome === ExternalIdentityResult::REGISTERED) {
            $request->session()->forget('referrer_id_candidate');
        }

        $request->session()->forget('url.intended');

        return redirect($ctx['continue_url']);
    }

    /** B2.4: نتیجه‌ی اتصال Google به کاربر واردشده؛ همیشه برگشت به Profile. */
    protected function finishLink(Request $request, array $ctx, \App\Channels\Website\Support\GoogleProfile $profile): RedirectResponse
    {
        $result = $this->linking->linkGoogle($request->user(), $profile->subject, $profile->email, $profile->emailVerified);
        $back = (string) $ctx['continue_url'];

        if ($result->isRejected()) {
            return redirect($back)->withErrors(['google' => match ($result->reason) {
                AccountLinkResult::REASON_OWNED_BY_OTHER => 'این حساب Google قبلاً به کاربر دیگری متصل است.',
                AccountLinkResult::REASON_ALREADY_HAS_PROVIDER => 'به این حساب یک Google دیگر وصل است. ابتدا آن را جدا کنید.',
                AccountLinkResult::REASON_PROVIDER_EMAIL_NOT_VERIFIED => 'ایمیل حساب Google شما تأیید نشده است.',
                AccountLinkResult::REASON_USER_NOT_ACTIVE => 'این حساب در حال حاضر قابل استفاده نیست.',
                default => 'اتصال Google انجام نشد. دوباره تلاش کنید.',
            }]);
        }

        return redirect($back)->with('status', $result->isAlready()
            ? 'این حساب Google از قبل به حساب شما وصل است.'
            : 'حساب Google شما با موفقیت وصل شد.');
    }

    /**
     * فروشگاه مبدأ ورود (G21). نبودن کلید `store` (Session قدیمی) ⇒ فروشگاه اصلی.
     * نماینده‌ای که دیگر وجود ندارد ⇒ null (ورود انجام می‌شود، عضویتی ساخته نمی‌شود).
     * فعال‌بودن نماینده را Core بررسی می‌کند.
     */
    protected function originStore(array $ctx): ?StoreContext
    {
        $origin = is_array($ctx['store'] ?? null) ? $ctx['store'] : [];

        if (($origin['store_type'] ?? 'main') !== 'reseller') {
            return StoreContext::main();
        }

        $reseller = Reseller::query()->find((int) ($origin['reseller_id'] ?? 0));

        return $reseller ? StoreContext::reseller($reseller) : null;
    }

    protected function fail(string $loginUrl, string $message): RedirectResponse
    {
        return redirect($loginUrl)->withErrors(['google' => $message]);
    }

    protected function messageFor(?string $reason): string
    {
        return match ($reason) {
            ExternalIdentityResult::REASON_EMAIL_NOT_VERIFIED => 'ایمیل حساب Google شما تأیید نشده است.',
            ExternalIdentityResult::REASON_LOCAL_EMAIL_UNVERIFIED => 'برای این ایمیل از قبل حسابی ثبت شده که ایمیلش هنوز تأیید نشده است. با رمز عبور وارد شوید و ایمیل را تأیید کنید، سپس دوباره تلاش کنید.',
            ExternalIdentityResult::REASON_LINK_REQUIRES_LOGIN => 'برای این ایمیل از قبل حسابی وجود دارد. با ایمیل و رمز عبور وارد شوید.',
            ExternalIdentityResult::REASON_USER_NOT_ACTIVE, ExternalIdentityResult::REASON_USER_UNAVAILABLE => 'این حساب در حال حاضر قابل استفاده نیست.',
            default => 'ورود با Google انجام نشد. دوباره تلاش کنید.',
        };
    }

    /** فقط URL هم‌Host با خود برنامه؛ هر چیز دیگر (خارجی/نامعتبر) ⇒ null. */
    protected function internalUrlOrNull(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true) && strcasecmp($parts['host'], $request->getHost()) === 0
            ? $url
            : null;
    }
}
