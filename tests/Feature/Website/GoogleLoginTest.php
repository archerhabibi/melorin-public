<?php

namespace Tests\Feature\Website;

use App\Channels\Website\Support\GoogleOAuthClient;
use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\GuestCheckout;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Wallet;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * B2.1 — Google Sign-In (docs/canonical/GOOGLE-SIGNIN-CONTRACT.md §G12–G18).
 * هیچ تماس واقعی به گوگل نمی‌رود؛ Token Endpoint با Http::fake شبیه‌سازی می‌شود.
 */
class GoogleLoginTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected const NONCE = 'nonce-value-for-tests';
    protected const STATE = 'state-value-for-tests';
    protected const VERIFIER = 'verifier-value-for-tests-0123456789-0123456789-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => null,
            'services.google.auto_link' => true,
        ]);
    }

    // ───────────────────────── Helpers ─────────────────────────

    protected function ctx(array $over = []): array
    {
        return array_merge([
            'state' => self::STATE,
            'nonce' => self::NONCE,
            'verifier' => self::VERIFIER,
            'created_at' => time(),
            'store' => ['store_type' => 'main', 'reseller_id' => null],
            'login_url' => route('website.login'),
            'continue_url' => route('website.home'),
        ], $over);
    }

    protected function idToken(array $over = []): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client-id',
            'sub' => 'google-sub-1',
            'email' => 'ali@example.test',
            'email_verified' => true,
            'name' => 'Ali Test',
            'nonce' => self::NONCE,
            'exp' => time() + 3600,
            'iat' => time(),
        ], $over);

        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64($claims).'.signature';
    }

    protected function fakeToken(array $claims = []): void
    {
        Http::fake([GoogleOAuthClient::TOKEN_ENDPOINT => Http::response(['id_token' => $this->idToken($claims)])]);
    }

    /** Callback با Session معتبر (یا override شده). */
    protected function hitCallback(array $ctx = [], array $query = [])
    {
        return $this->withSession(['google_oauth' => $this->ctx($ctx)])
            ->get(route('auth.google.callback', array_merge(['state' => self::STATE, 'code' => 'auth-code'], $query)));
    }

    protected function auditExists(string $action): bool
    {
        return AuditLog::query()->where('action', $action)->exists();
    }

    // ───────────────────────── Feature flag / UI ─────────────────────────

    #[Test]
    public function the_feature_is_fully_off_without_credentials(): void
    {
        config(['services.google.enabled' => false, 'services.google.client_id' => null]);

        $this->get(route('website.login'))->assertOk()->assertDontSee('ادامه با Google');
        $this->get(route('website.auth.google.redirect'))->assertNotFound();
        $this->get(route('auth.google.callback', ['state' => 'x', 'code' => 'y']))->assertNotFound();
    }

    #[Test]
    public function login_and_register_show_the_google_button_when_enabled(): void
    {
        $this->get(route('website.login'))->assertOk()
            ->assertSee('ادامه با Google')->assertSee(route('website.auth.google.redirect'), false);
        $this->get(route('website.register'))->assertOk()->assertSee('ادامه با Google');
    }

    #[Test]
    public function the_reseller_store_login_page_points_to_the_store_scoped_start_route(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active', 'slug' => 'acme']);

        $this->get(route('website.store.login', $reseller->slug))->assertOk()
            ->assertSee(route('website.store.auth.google.redirect', $reseller->slug), false);
    }

    // ───────────────────────── Redirect (PKCE/state/nonce) ─────────────────────────

    #[Test]
    public function redirect_builds_a_pkce_authorization_url_and_stores_one_time_secrets_in_session(): void
    {
        $response = $this->get(route('website.auth.google.redirect'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(GoogleOAuthClient::AUTH_ENDPOINT.'?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $ctx = session('google_oauth');

        $this->assertSame('test-client-id', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(route('auth.google.callback'), $q['redirect_uri']);
        $this->assertSame($ctx['state'], $q['state']);
        $this->assertSame($ctx['nonce'], $q['nonce']);
        $this->assertSame(GoogleOAuthClient::codeChallenge($ctx['verifier']), $q['code_challenge']);
        $this->assertGreaterThanOrEqual(43, strlen($ctx['verifier']));
        // Secret هرگز در URL نمی‌آید.
        $this->assertStringNotContainsString('test-client-secret', $location);
        $this->assertStringNotContainsString($ctx['verifier'], $location);
    }

    #[Test]
    public function redirect_never_trusts_an_external_intended_url(): void
    {
        $this->withSession(['url.intended' => 'https://evil.example/steal'])
            ->get(route('website.auth.google.redirect'));

        $this->assertSame(route('website.home'), session('google_oauth')['continue_url']);
    }

    #[Test]
    public function redirect_keeps_a_same_host_intended_url(): void
    {
        $this->withSession(['url.intended' => route('website.orders.index')])
            ->get(route('website.auth.google.redirect'));

        $this->assertSame(route('website.orders.index'), session('google_oauth')['continue_url']);
    }

    // ───────────────────────── Callback: موفق ─────────────────────────

    #[Test]
    public function a_new_google_user_is_registered_verified_passwordless_and_logged_in(): void
    {
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.home'));

        $user = User::query()->where('email', 'ali@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
        $this->assertSame('Ali Test', $user->full_name);
        $this->assertSame('website', $user->joined_from);
        $this->assertSame('active', $user->status);

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1',
        ]);
        // G21: ثبت‌نام با Google، CustomerAccount فروشگاه اصلی را می‌سازد (نه Wallet/Order).
        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'store_type' => 'main', 'reseller_id' => null,
            'status' => 'active', 'display_name' => 'Ali Test',
        ]);
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertTrue($this->auditExists('identity.google_registered'));
        $this->assertTrue($this->auditExists('identity.google_customer_account_created'));
    }

    #[Test]
    public function the_token_exchange_sends_the_pkce_verifier_and_secret_server_side(): void
    {
        $this->fakeToken();

        $this->hitCallback();

        Http::assertSent(fn ($r) => $r->url() === GoogleOAuthClient::TOKEN_ENDPOINT
            && $r['code'] === 'auth-code'
            && $r['code_verifier'] === self::VERIFIER
            && $r['client_secret'] === 'test-client-secret'
            && $r['grant_type'] === 'authorization_code');
    }

    #[Test]
    public function an_existing_google_identity_logs_in_without_creating_anything(): void
    {
        $user = User::factory()->create(['telegram_id' => null, 'email' => 'old@example.test', 'email_verified_at' => now()]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);
        // Email عوض شده در گوگل؛ کلید تطبیق sub است، نه Email.
        $this->fakeToken(['email' => 'renamed@example.test']);

        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, UserIdentity::query()->count());
        $this->assertTrue($this->auditExists('identity.google_login'));
    }

    #[Test]
    public function a_verified_local_user_with_the_same_email_is_linked(): void
    {
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'ali@example.test',
            'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'provider_user_id' => 'google-sub-1']);
        $this->assertTrue($this->auditExists('identity.google_linked'));
    }

    #[Test]
    public function email_matching_is_case_insensitive(): void
    {
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'Ali@Example.test', 'email_verified_at' => now(),
        ]);
        $this->fakeToken(['email' => 'ali@example.test']);

        $this->hitCallback();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function a_pending_registration_referrer_is_applied_only_to_new_google_users(): void
    {
        $referrer = User::factory()->create();
        $this->fakeToken();

        $this->withSession(['google_oauth' => $this->ctx(), 'referrer_id_candidate' => $referrer->id])
            ->get(route('auth.google.callback', ['state' => self::STATE, 'code' => 'c']));

        $this->assertSame($referrer->id, User::query()->where('email', 'ali@example.test')->value('referrer_id'));
    }

    // ───────────────────────── Callback: رد (Fail-closed) ─────────────────────────

    #[Test]
    public function an_unverified_local_account_is_never_linked_or_logged_in(): void
    {
        // سناریوی Pre-hijack: مهاجم با Email قربانی ثبت‌نام کرده و تأیید نشده.
        $attacker = User::factory()->create([
            'telegram_id' => null, 'email' => 'ali@example.test',
            'password' => 'attacker-password', 'email_verified_at' => null,
        ]);
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.login'))->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->count());
        $this->assertNull($attacker->fresh()->email_verified_at);
        $this->assertTrue($this->auditExists('identity.google_rejected'));
    }

    #[Test]
    public function auto_link_can_be_disabled_to_require_a_password_login_first(): void
    {
        config(['services.google.auto_link' => false]);
        User::factory()->create(['telegram_id' => null, 'email' => 'ali@example.test', 'email_verified_at' => now()]);
        $this->fakeToken();

        $this->hitCallback()->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->count());
    }

    #[Test]
    public function an_unverified_google_email_creates_nothing(): void
    {
        $this->fakeToken(['email_verified' => false]);

        $this->hitCallback()->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, UserIdentity::query()->count());
    }

    #[Test]
    public function email_verified_as_the_string_true_is_accepted(): void
    {
        $this->fakeToken(['email_verified' => 'true']);

        $this->hitCallback();

        $this->assertAuthenticated();
    }

    #[Test]
    public function inactive_users_cannot_log_in_with_google_by_identity_or_by_email(): void
    {
        $blocked = User::factory()->create([
            'telegram_id' => null, 'email' => 'blocked@example.test', 'email_verified_at' => now(), 'status' => 'blocked',
        ]);
        UserIdentity::create(['user_id' => $blocked->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);
        $this->fakeToken();
        $this->hitCallback()->assertSessionHasErrors('google');
        $this->assertGuest();

        $disabled = User::factory()->create([
            'telegram_id' => null, 'email' => 'disabled@example.test', 'email_verified_at' => now(), 'status' => 'disabled',
        ]);
        $this->fakeToken(['sub' => 'google-sub-2', 'email' => 'disabled@example.test']);
        $this->hitCallback()->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertSame(0, $disabled->identities()->count());
    }

    #[Test]
    public function a_soft_deleted_user_is_not_resurrected_or_duplicated(): void
    {
        $gone = User::factory()->create(['telegram_id' => null, 'email' => 'ali@example.test', 'email_verified_at' => now()]);
        $gone->delete();
        $this->fakeToken();

        $this->hitCallback()->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, UserIdentity::query()->count());
    }

    #[Test]
    public function a_state_mismatch_is_rejected_before_any_token_call(): void
    {
        Http::fake();

        $this->hitCallback([], ['state' => 'attacker-state'])->assertSessionHasErrors('google');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[Test]
    public function a_callback_without_a_started_flow_is_rejected(): void
    {
        Http::fake();

        $this->get(route('auth.google.callback', ['state' => self::STATE, 'code' => 'c']))
            ->assertRedirect(route('website.login'))->assertSessionHasErrors('google');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    #[Test]
    public function an_expired_flow_is_rejected(): void
    {
        Http::fake();

        $this->hitCallback(['created_at' => time() - 601])->assertSessionHasErrors('google');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[Test]
    public function the_session_secrets_are_one_time_use(): void
    {
        $this->fakeToken();
        $this->hitCallback()->assertRedirect(route('website.home'));
        $this->assertNull(session('google_oauth'));
    }

    #[Test]
    public function the_user_cancelling_at_google_is_handled_gracefully(): void
    {
        Http::fake();

        $this->withSession(['google_oauth' => $this->ctx()])
            ->get(route('auth.google.callback', ['state' => self::STATE, 'error' => 'access_denied']))
            ->assertRedirect(route('website.login'))->assertSessionHasErrors('google');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    #[Test]
    public function a_nonce_mismatch_is_rejected(): void
    {
        $this->fakeToken(['nonce' => 'someone-elses-nonce']);

        $this->hitCallback()->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function a_token_for_another_audience_issuer_or_expired_is_rejected(): void
    {
        foreach ([['aud' => 'other-client'], ['iss' => 'https://evil.example'], ['exp' => time() - 3600], ['sub' => '']] as $bad) {
            $this->fakeToken($bad);
            $this->hitCallback()->assertSessionHasErrors('google');
            $this->assertGuest();
        }

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function a_failing_token_endpoint_is_handled_without_a_500_or_user(): void
    {
        Http::fake([GoogleOAuthClient::TOKEN_ENDPOINT => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->hitCallback()->assertRedirect(route('website.login'))->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    // ───────────────────────── Password Login: ایمنی کاربر بدون رمز / غیرفعال ─────────────────────────

    #[Test]
    public function password_login_for_a_google_only_account_fails_generically_without_a_500(): void
    {
        User::factory()->create([
            'telegram_id' => null, 'email' => 'ali@example.test', 'email_verified_at' => now(),
        ]);

        $this->post(route('website.login.store'), ['email' => 'ali@example.test', 'password' => 'anything'])
            ->assertSessionHasErrors(['email' => 'ایمیل یا رمز عبور اشتباه است.']);

        $this->assertGuest();
    }

    #[Test]
    public function password_login_is_refused_for_inactive_users(): void
    {
        User::factory()->create([
            'telegram_id' => null, 'email' => 'blocked@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(), 'status' => 'blocked',
        ]);

        $this->post(route('website.login.store'), ['email' => 'blocked@example.test', 'password' => 'a-strong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ───────────────────────── Guest continuation (G6) و Reseller ─────────────────────────

    #[Test]
    public function google_login_continues_the_same_pending_guest_purchase(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 90000);
        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'ali@example.test']);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)->get(route('website.auth.google.redirect'));

        $continue = session('google_oauth')['continue_url'];
        $this->assertSame(route('website.checkout.show', $product->id), $continue);

        $this->fakeToken();
        $this->hitCallback(['continue_url' => $continue])->assertRedirect($continue);

        // G3/G6: از داده‌ی Guest هیچ User/Purchase ساخته نمی‌شود؛ فقط همان User واردشده + همان GuestCheckout.
        // G21: عضویت از خودِ ورود Google ساخته شده (نه از Guest)؛ Checkout بعدی همان را Resolve می‌کند.
        $user = User::query()->firstOrFail();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, GuestCheckout::query()->count());
        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, Order::query()->count());

        app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        $this->assertSame(1, CustomerAccount::query()->count());
    }

    #[Test]
    public function starting_from_a_reseller_store_returns_to_that_store(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active', 'slug' => 'acme']);

        $this->get(route('website.store.auth.google.redirect', $reseller->slug));

        $ctx = session('google_oauth');
        $this->assertSame(route('website.store.login', $reseller->slug), $ctx['login_url']);
        $this->assertSame(route('website.store.home', $reseller->slug), $ctx['continue_url']);

        $this->fakeToken();
        $this->hitCallback(['store' => $ctx['store'], 'login_url' => $ctx['login_url'], 'continue_url' => $ctx['continue_url']])
            ->assertRedirect(route('website.store.home', $reseller->slug));

        // User مرکزی است (R2)؛ G21: عضویت فقط در همان فروشگاه نماینده ساخته می‌شود (نه فروشگاه اصلی).
        $this->assertAuthenticated();
        $user = User::query()->where('email', 'ali@example.test')->firstOrFail();
        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id, 'status' => 'active',
        ]);
        $this->assertSame(0, Wallet::query()->count());
    }

    #[Test]
    public function the_authenticated_user_is_not_served_the_callback(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('auth.google.callback', ['state' => 'x', 'code' => 'y']))
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    // ───────────────────────── G21: CustomerAccount از ورود Google ─────────────────────────

    #[Test]
    public function redirect_records_the_origin_store_server_side(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active', 'slug' => 'acme']);

        $this->get(route('website.auth.google.redirect'));
        $this->assertSame(['store_type' => 'main', 'reseller_id' => null], session('google_oauth')['store']);

        $this->get(route('website.store.auth.google.redirect', $reseller->slug));
        $this->assertSame(['store_type' => 'reseller', 'reseller_id' => $reseller->id], session('google_oauth')['store']);
    }

    #[Test]
    public function an_existing_google_identity_gets_a_customer_account_on_login_and_it_is_never_duplicated(): void
    {
        $user = User::factory()->create(['telegram_id' => null, 'email' => 'old@example.test', 'email_verified_at' => now()]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);
        $this->assertSame(0, CustomerAccount::query()->count());

        $this->fakeToken();
        $this->hitCallback()->assertRedirect(route('website.home'));
        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'identity.google_customer_account_created')->count());

        auth()->logout();
        $this->fakeToken();
        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'identity.google_customer_account_created')->count());
    }

    #[Test]
    public function linking_a_verified_local_user_also_ensures_the_customer_account(): void
    {
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'ali@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertTrue($this->auditExists('identity.google_linked'));
        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function an_existing_customer_account_is_reused_untouched(): void
    {
        $user = User::factory()->create(['telegram_id' => null, 'email' => 'ali@example.test', 'email_verified_at' => now()]);
        $existing = CustomerAccount::create([
            'user_id' => $user->id, 'store_type' => 'main', 'reseller_id' => null,
            'status' => 'disabled', 'display_name' => 'Custom Name',
        ]);
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertSame('disabled', $existing->fresh()->status);
        $this->assertSame('Custom Name', $existing->fresh()->display_name);
        $this->assertFalse($this->auditExists('identity.google_customer_account_created'));
    }

    #[Test]
    public function the_same_user_gets_separate_accounts_per_store_without_merging(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active', 'slug' => 'acme']);
        $reseller->refresh();

        $this->fakeToken();
        $this->hitCallback()->assertRedirect(route('website.home'));
        auth()->logout();

        $this->fakeToken();
        $this->hitCallback(['store' => ['store_type' => 'reseller', 'reseller_id' => $reseller->id]]);

        $user = User::query()->where('email', 'ali@example.test')->firstOrFail();
        $this->assertSame(1, User::query()->where('email', 'ali@example.test')->count());
        $this->assertSame(2, CustomerAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->where('store_type', 'main')->count());
        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->where('reseller_id', $reseller->id)->count());
    }

    #[Test]
    public function no_customer_account_is_created_for_a_missing_or_inactive_reseller_but_login_still_works(): void
    {
        $inactive = Reseller::factory()->create(['status' => 'inactive', 'slug' => 'gone']);

        $this->fakeToken();
        $this->hitCallback(['store' => ['store_type' => 'reseller', 'reseller_id' => $inactive->id]])
            ->assertRedirect(route('website.home'));
        $this->assertAuthenticated();
        $this->assertSame(0, CustomerAccount::query()->count());

        auth()->logout();
        $this->fakeToken();
        $this->hitCallback(['store' => ['store_type' => 'reseller', 'reseller_id' => 999999]])
            ->assertRedirect(route('website.home'));
        $this->assertAuthenticated();
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    #[Test]
    public function rejected_google_attempts_never_create_a_customer_account(): void
    {
        $blocked = User::factory()->create([
            'telegram_id' => null, 'email' => 'blocked@example.test', 'email_verified_at' => now(), 'status' => 'blocked',
        ]);
        UserIdentity::create(['user_id' => $blocked->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-1']);
        $this->fakeToken();
        $this->hitCallback()->assertSessionHasErrors('google');

        $this->fakeToken(['sub' => 'google-sub-9', 'email' => 'new@example.test', 'email_verified' => false]);
        $this->hitCallback()->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    #[Test]
    public function a_customer_account_failure_does_not_break_the_login(): void
    {
        $this->mock(IdentityService::class)
            ->shouldReceive('findCustomerAccount')->andThrow(new \RuntimeException('db down'));
        $this->fakeToken();

        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertAuthenticated();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, UserIdentity::query()->count());
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    // ───────────────────────── G21: ورود Email+Password هم عضویت می‌سازد ─────────────────────────

    #[Test]
    public function a_successful_password_login_creates_the_main_store_customer_account_once(): void
    {
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'pw@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);
        $this->assertSame(0, CustomerAccount::query()->count());

        $this->post(route('website.login.store'), ['email' => 'pw@example.test', 'password' => 'a-strong-password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'store_type' => 'main', 'reseller_id' => null, 'status' => 'active',
        ]);
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'identity.password_customer_account_created')->count());

        auth()->logout();
        $this->post(route('website.login.store'), ['email' => 'pw@example.test', 'password' => 'a-strong-password']);

        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'identity.password_customer_account_created')->count());
    }

    #[Test]
    public function a_password_login_from_a_reseller_store_creates_only_that_stores_account(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active', 'slug' => 'acme']);
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'pw@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);

        $this->post(route('website.store.login.store', $reseller->slug), ['email' => 'pw@example.test', 'password' => 'a-strong-password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertDatabaseHas('customer_accounts', [
            'user_id' => $user->id, 'store_type' => 'reseller', 'reseller_id' => $reseller->id,
        ]);
    }

    #[Test]
    public function a_failed_or_refused_password_login_creates_no_customer_account(): void
    {
        User::factory()->create([
            'telegram_id' => null, 'email' => 'pw@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);
        User::factory()->create([
            'telegram_id' => null, 'email' => 'blocked@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(), 'status' => 'blocked',
        ]);

        $this->post(route('website.login.store'), ['email' => 'pw@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('website.login.store'), ['email' => 'blocked@example.test', 'password' => 'a-strong-password'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    #[Test]
    public function google_and_password_logins_share_one_account_per_store(): void
    {
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'ali@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);

        $this->post(route('website.login.store'), ['email' => 'ali@example.test', 'password' => 'a-strong-password']);
        auth()->logout();

        $this->fakeToken();
        $this->hitCallback()->assertRedirect(route('website.home'));

        $this->assertSame(1, CustomerAccount::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_password_login_still_succeeds_when_the_membership_cannot_be_created(): void
    {
        User::factory()->create([
            'telegram_id' => null, 'email' => 'pw@example.test', 'password' => 'a-strong-password', 'email_verified_at' => now(),
        ]);
        $this->mock(IdentityService::class)
            ->shouldReceive('findCustomerAccount')->andThrow(new \RuntimeException('db down'));

        $this->post(route('website.login.store'), ['email' => 'pw@example.test', 'password' => 'a-strong-password'])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    #[Test]
    public function the_start_route_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get(route('website.auth.google.redirect'))->assertRedirect();
        }

        $this->get(route('website.auth.google.redirect'))->assertStatus(429);
    }
}
