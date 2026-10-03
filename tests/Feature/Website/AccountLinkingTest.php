<?php

namespace Tests\Feature\Website;

use App\Channels\Website\Support\GoogleOAuthClient;
use App\Models\AuditLog;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B2.4 — Account Linking (docs/canonical/ACCOUNT-LINKING-CONTRACT.md).
 * هیچ تماس واقعی به Google/Telegram نمی‌رود.
 */
class AccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected const NONCE = 'nonce-link';
    protected const STATE = 'state-link';
    protected const VERIFIER = 'verifier-link-0123456789-0123456789-0123456789-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => null,
            'telegram.bots.main.token' => 'test-bot-token-123',
            'telegram.bots.main.username' => 'MelorinTestBot',
        ]);
    }

    protected function user(array $over = []): User
    {
        return User::factory()->create(array_merge([
            'telegram_id' => null,
            'email' => 'member@example.test',
            'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ], $over));
    }

    protected function googleOnlyUser(string $sub = 'g-sub-1'): User
    {
        $user = $this->user(['password' => null]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => $sub, 'provider_email' => $user->email]);

        return $user;
    }

    protected function linkCtx(User $user, array $over = []): array
    {
        return array_merge([
            'mode' => 'link',
            'user_id' => $user->id,
            'state' => self::STATE,
            'nonce' => self::NONCE,
            'verifier' => self::VERIFIER,
            'created_at' => time(),
            'store' => ['store_type' => 'main', 'reseller_id' => null],
            'login_url' => route('website.identity.profile.show'),
            'continue_url' => route('website.identity.profile.show'),
        ], $over);
    }

    protected function fakeToken(array $claims = []): void
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com', 'aud' => 'test-client-id', 'sub' => 'google-sub-new',
            'email' => 'other-mail@gmail.test', 'email_verified' => true, 'name' => 'Ali',
            'nonce' => self::NONCE, 'exp' => time() + 3600, 'iat' => time(),
        ], $claims);
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        Http::fake([GoogleOAuthClient::TOKEN_ENDPOINT => Http::response([
            'id_token' => $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64($claims).'.sig',
        ])]);
    }

    protected function hitCallback(User $actor, array $ctx, array $query = [])
    {
        return $this->actingAs($actor)
            ->withSession(['google_oauth' => $ctx])
            ->get(route('auth.google.callback', array_merge(['state' => self::STATE, 'code' => 'auth-code'], $query)));
    }

    protected function audited(string $action): bool
    {
        return AuditLog::query()->where('action', $action)->exists();
    }

    // ───────────────────────── Google: شروع ─────────────────────────

    #[Test]
    public function starting_a_google_link_requires_login(): void
    {
        $this->get(route('website.identity.google.link'))->assertRedirect();
        $this->assertNull(session('google_oauth'));
    }

    #[Test]
    public function starting_a_google_link_stores_a_server_side_link_context(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->get(route('website.identity.google.link'));

        $response->assertRedirectContains(GoogleOAuthClient::AUTH_ENDPOINT);
        $ctx = session('google_oauth');
        $this->assertSame('link', $ctx['mode']);
        $this->assertSame($user->id, $ctx['user_id']);
        $this->assertSame(route('website.identity.profile.show'), $ctx['continue_url']);
    }

    #[Test]
    public function the_link_start_is_a_404_when_google_is_disabled(): void
    {
        config(['services.google.enabled' => false, 'services.google.client_id' => null]);

        $this->actingAs($this->user())->get(route('website.identity.google.link'))->assertNotFound();
    }

    // ───────────────────────── Google: callback ─────────────────────────

    #[Test]
    public function a_logged_in_user_links_google_even_when_the_google_email_differs(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user))
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-sub-new',
        ]);
        $this->assertSame('member@example.test', $user->fresh()->email); // Email حساب دست نمی‌خورد
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.google_linked', 'actor_type' => 'customer', 'actor_id' => $user->id,
        ]);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function linking_creates_no_customer_account_wallet_or_order(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user));

        $this->assertEquals(0, CustomerAccount::query()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('users', 1);
    }

    #[Test]
    public function a_google_account_owned_by_another_user_is_rejected_not_merged(): void
    {
        $owner = $this->googleOnlyUser('google-sub-new');
        $user = $this->user(['email' => 'second@example.test']);
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user))
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHasErrors('google');

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id]);
        $this->assertEquals($owner->id, UserIdentity::query()->where('provider_user_id', 'google-sub-new')->value('user_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.google_link_rejected', 'actor_id' => $user->id]);
    }

    #[Test]
    public function the_rejection_audit_carries_the_reason_but_no_email(): void
    {
        $this->googleOnlyUser('google-sub-new');
        $user = $this->user(['email' => 'second@example.test']);
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user));

        $log = AuditLog::query()->where('action', 'identity.google_link_rejected')->firstOrFail();
        $this->assertSame('owned_by_other', $log->after['reason']);
        $this->assertStringNotContainsString('other-mail@gmail.test', json_encode($log->after));
    }

    #[Test]
    public function a_user_who_already_has_another_google_cannot_silently_replace_it(): void
    {
        $user = $this->googleOnlyUser('google-sub-old');
        $this->fakeToken(['sub' => 'google-sub-new']);

        $this->hitCallback($user, $this->linkCtx($user))->assertSessionHasErrors('google');

        $this->assertSame('google-sub-old', UserIdentity::query()->where('user_id', $user->id)->value('provider_user_id'));
    }

    #[Test]
    public function linking_the_same_google_twice_is_idempotent(): void
    {
        $user = $this->googleOnlyUser('google-sub-new');
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user))->assertSessionHas('status');

        $this->assertEquals(1, UserIdentity::query()->where('user_id', $user->id)->count());
        $this->assertFalse($this->audited('identity.google_link_rejected'));
    }

    #[Test]
    public function an_unverified_google_email_is_rejected(): void
    {
        $user = $this->user();
        $this->fakeToken(['email_verified' => false]);

        $this->hitCallback($user, $this->linkCtx($user))->assertSessionHasErrors('google');

        $this->assertDatabaseCount('user_identities', 0);
    }

    #[Test]
    public function a_state_mismatch_never_links(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user), ['state' => 'forged'])->assertSessionHasErrors('google');

        $this->assertDatabaseCount('user_identities', 0);
    }

    #[Test]
    public function a_link_context_of_another_user_is_refused(): void
    {
        $victim = $this->user();
        $attacker = $this->user(['email' => 'attacker@example.test']);
        $this->fakeToken();

        // نشست اتصال برای $attacker ساخته شده ولی $victim واردشده است.
        $this->hitCallback($victim, $this->linkCtx($attacker))->assertSessionHasErrors('google');

        $this->assertDatabaseCount('user_identities', 0);
    }

    #[Test]
    public function a_link_context_without_a_logged_in_user_links_nothing(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->withSession(['google_oauth' => $this->linkCtx($user)])
            ->get(route('auth.google.callback', ['state' => self::STATE, 'code' => 'auth-code']))
            ->assertRedirect(route('website.login'));

        $this->assertDatabaseCount('user_identities', 0);
        $this->assertGuest();
    }

    #[Test]
    public function a_logged_in_user_with_a_login_context_is_not_served_the_callback(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user, ['mode' => null]))
            ->assertRedirect(route('website.home'));

        $this->assertDatabaseCount('user_identities', 0);
    }

    #[Test]
    public function the_link_context_is_single_use(): void
    {
        $user = $this->user();
        $this->fakeToken();

        $this->hitCallback($user, $this->linkCtx($user));
        $this->assertNull(session('google_oauth'));

        $this->actingAs($user)
            ->get(route('auth.google.callback', ['state' => self::STATE, 'code' => 'auth-code']))
            ->assertRedirect(route('website.home'));
    }

    // ───────────────────────── Google: unlink ─────────────────────────

    #[Test]
    public function a_user_with_a_password_can_unlink_google_after_confirming_it(): void
    {
        $user = $this->user();
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g1']);

        $this->actingAs($user)
            ->post(route('website.identity.google.unlink'), ['current_password' => 'a-strong-password'])
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('user_identities', 0);
        $this->assertTrue($this->audited('identity.google_unlinked'));
    }

    #[Test]
    public function unlinking_google_with_a_wrong_password_changes_nothing(): void
    {
        $user = $this->user();
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g1']);

        $this->actingAs($user)
            ->post(route('website.identity.google.unlink'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('google');

        $this->assertDatabaseCount('user_identities', 1);
    }

    #[Test]
    public function unlinking_google_without_the_password_field_is_refused(): void
    {
        $user = $this->user();
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g1']);

        $this->actingAs($user)->post(route('website.identity.google.unlink'))->assertSessionHasErrors('current_password');

        $this->assertDatabaseCount('user_identities', 1);
    }

    #[Test]
    public function google_cannot_be_unlinked_when_it_is_the_only_login_method(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->post(route('website.identity.google.unlink'))
            ->assertSessionHasErrors('google');

        $this->assertDatabaseCount('user_identities', 1);
        $this->assertTrue($this->audited('identity.google_unlink_rejected'));
    }

    #[Test]
    public function unlinking_when_nothing_is_linked_is_a_harmless_error(): void
    {
        $this->actingAs($this->user())
            ->post(route('website.identity.google.unlink'), ['current_password' => 'a-strong-password'])
            ->assertSessionHasErrors('google');
    }

    #[Test]
    public function the_unlink_routes_require_login(): void
    {
        $this->post(route('website.identity.google.unlink'))->assertRedirect();
        $this->post(route('website.identity.telegram.unlink'))->assertRedirect();
        $this->post(route('website.identity.password.set'))->assertRedirect();
    }

    // ───────────────────────── Password (D-13) ─────────────────────────

    #[Test]
    public function a_google_only_user_can_set_a_first_password_and_then_unlink_google(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->post(route('website.identity.password.set'), ['password' => 'Brand-new-pass-1!', 'password_confirmation' => 'Brand-new-pass-1!'])
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHas('status');

        $this->assertNotNull($user->fresh()->getRawOriginal('password'));
        $this->assertTrue($this->audited('identity.password_set'));

        $this->actingAs($user->fresh())
            ->post(route('website.identity.google.unlink'), ['current_password' => 'Brand-new-pass-1!'])
            ->assertSessionHas('status');

        $this->assertDatabaseCount('user_identities', 0);
    }

    #[Test]
    public function setting_a_password_requires_a_matching_confirmation(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->post(route('website.identity.password.set'), ['password' => 'Brand-new-pass-1!', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->getRawOriginal('password'));
    }

    #[Test]
    public function the_set_password_route_never_overwrites_an_existing_password(): void
    {
        $user = $this->user();
        $before = $user->getRawOriginal('password');

        $this->actingAs($user)
            ->post(route('website.identity.password.set'), ['password' => 'Brand-new-pass-1!', 'password_confirmation' => 'Brand-new-pass-1!'])
            ->assertSessionHasErrors('password');

        $this->assertSame($before, $user->fresh()->getRawOriginal('password'));
    }

    #[Test]
    public function an_unverified_email_cannot_set_a_password_from_the_profile(): void
    {
        $user = $this->user(['password' => null, 'email_verified_at' => null]);

        $this->actingAs($user)
            ->post(route('website.identity.password.set'), ['password' => 'Brand-new-pass-1!', 'password_confirmation' => 'Brand-new-pass-1!'])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->getRawOriginal('password'));
    }

    // ───────────────────────── Telegram ─────────────────────────

    protected function telegramPayload(int $id = 555000111): array
    {
        $data = ['id' => $id, 'first_name' => 'Ali', 'auth_date' => time()];
        $check = collect($data)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $data['hash'] = hash_hmac('sha256', $check, hash('sha256', 'test-bot-token-123', true));
        $data['state'] = 'tg-state';

        return $data;
    }

    #[Test]
    public function a_user_with_a_different_telegram_cannot_silently_replace_it(): void
    {
        $user = $this->user(['telegram_id' => 111222333]);

        $this->actingAs($user)->withSession(['telegram_link_state' => 'tg-state'])
            ->get(route('website.identity.telegram.callback', $this->telegramPayload()))
            ->assertSessionHasErrors('telegram');

        $this->assertEquals(111222333, $user->fresh()->telegram_id);
        $this->assertTrue($this->audited('identity.telegram_link_rejected'));
    }

    #[Test]
    public function relinking_the_same_telegram_is_idempotent(): void
    {
        $user = $this->user(['telegram_id' => 555000111]);

        $this->actingAs($user)->withSession(['telegram_link_state' => 'tg-state'])
            ->get(route('website.identity.telegram.callback', $this->telegramPayload()))
            ->assertSessionHas('status');

        $this->assertFalse($this->audited('identity.telegram_link_rejected'));
    }

    #[Test]
    public function telegram_can_be_unlinked_by_a_user_with_a_password(): void
    {
        $user = $this->user(['telegram_id' => 555000111]);

        $this->actingAs($user)
            ->post(route('website.identity.telegram.unlink'), ['current_password' => 'a-strong-password'])
            ->assertSessionHas('status');

        $this->assertNull($user->fresh()->telegram_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.telegram_unlinked', 'actor_id' => $user->id]);
    }

    #[Test]
    public function unlinking_telegram_with_a_wrong_password_changes_nothing(): void
    {
        $user = $this->user(['telegram_id' => 555000111]);

        $this->actingAs($user)
            ->post(route('website.identity.telegram.unlink'), ['current_password' => 'nope-nope-nope'])
            ->assertSessionHasErrors('telegram');

        $this->assertEquals(555000111, $user->fresh()->telegram_id);
    }

    #[Test]
    public function a_telegram_only_bot_user_cannot_unlink_telegram(): void
    {
        $user = User::factory()->create(['telegram_id' => 555000111, 'email' => null, 'password' => null, 'joined_from' => 'bot']);

        $this->actingAs($user)
            ->post(route('website.identity.telegram.unlink'))
            ->assertSessionHasErrors('telegram');

        $this->assertEquals(555000111, $user->fresh()->telegram_id);
        $this->assertTrue($this->audited('identity.telegram_unlink_rejected'));
    }

    #[Test]
    public function a_google_only_user_can_unlink_telegram_without_a_password_prompt(): void
    {
        $user = $this->googleOnlyUser();
        $user->update(['telegram_id' => 555000111]);

        $this->actingAs($user)
            ->post(route('website.identity.telegram.unlink'))
            ->assertSessionHas('status');

        $this->assertNull($user->fresh()->telegram_id);
    }

    // ───────────────────────── Profile UI ─────────────────────────

    #[Test]
    public function the_profile_offers_google_link_and_a_password_form_to_a_google_only_user(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('روش‌های ورود')
            ->assertSee(route('website.identity.password.set'), false)
            ->assertSee('تنها روش ورود شماست')
            ->assertDontSee(route('website.identity.google.link'), false);
    }

    #[Test]
    public function the_profile_offers_the_google_link_button_when_not_linked(): void
    {
        $this->actingAs($this->user())->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('اتصال حساب Google')
            ->assertSee(route('website.identity.google.link'), false);
    }

    #[Test]
    public function the_profile_hides_google_entirely_when_disabled_and_not_linked(): void
    {
        config(['services.google.enabled' => false]);

        $this->actingAs($this->user())->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee('اتصال حساب Google');
    }

    #[Test]
    public function the_profile_shows_unlink_with_password_confirmation_for_a_linked_user(): void
    {
        $user = $this->user();
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g1', 'provider_email' => 'g@example.test']);

        $this->actingAs($user)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('g@example.test')
            ->assertSee(route('website.identity.google.unlink'), false)
            ->assertSee('current_password', false);
    }
}
