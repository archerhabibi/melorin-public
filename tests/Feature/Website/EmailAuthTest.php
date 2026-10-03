<?php

namespace Tests\Feature\Website;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Core\Identity\EmailAuthService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B2.2 — Email Authentication (docs/canonical/EMAIL-AUTH-CONTRACT.md §E1–E6).
 */
class EmailAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function user(array $over = []): User
    {
        return User::factory()->create(array_merge([
            'telegram_id' => null, 'email' => 'ali@example.test',
            'password' => 'old-strong-password', 'email_verified_at' => now(),
        ], $over));
    }

    protected function audit(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    protected function reset(string $email, string $token, string $password = 'new-strong-password-1')
    {
        return $this->post(route('website.password.store'), [
            'token' => $token, 'email' => $email,
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    // ───────────────────────── E1: نرمال‌سازی ─────────────────────────

    #[Test]
    public function registration_stores_the_email_trimmed_and_lowercase(): void
    {
        Notification::fake();

        $this->post(route('website.register.store'), [
            'full_name' => 'New', 'email' => '  New.User@Example.TEST ',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseHas('users', ['email' => 'new.user@example.test']);
    }

    #[Test]
    public function registration_rejects_an_email_that_differs_only_by_case_or_belongs_to_a_deleted_user(): void
    {
        $this->user(['email' => 'Ali@Example.test']); // ردیف قدیمیِ Mixed-case
        $gone = $this->user(['email' => 'gone@example.test']);
        $gone->delete();

        foreach (['ali@example.test', 'ALI@EXAMPLE.TEST', 'gone@example.test'] as $email) {
            $this->post(route('website.register.store'), [
                'full_name' => 'Dup', 'email' => $email,
                'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
            ])->assertSessionHasErrors(['email' => 'این ایمیل قبلاً ثبت شده است.']);
        }

        $this->assertSame(1, User::query()->count());
    }

    #[Test]
    public function login_is_case_insensitive_even_for_legacy_mixed_case_rows(): void
    {
        $user = $this->user(['email' => 'Ali@Example.test']);

        $this->post(route('website.login.store'), ['email' => ' ALI@example.TEST ', 'password' => 'old-strong-password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function login_rate_limit_is_shared_across_email_case_variants(): void
    {
        $this->user();
        // Throttle سطح Route (IP) کنار گذاشته می‌شود تا فقط محدودیت per-email سنجیده شود.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('website.login.store'), ['email' => $i % 2 ? 'ALI@example.test' : 'ali@example.test', 'password' => 'wrong']);
        }

        $this->post(route('website.login.store'), ['email' => 'Ali@Example.test', 'password' => 'old-strong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function forgot_password_works_for_any_email_case_and_stays_neutral_for_unknown_emails(): void
    {
        Notification::fake();
        $user = $this->user(['email' => 'Ali@Example.test']);

        $this->post(route('website.password.email'), ['email' => 'ALI@example.test'])
            ->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $this->post(route('website.password.email'), ['email' => 'nobody@example.test'])
            ->assertSessionHas('status');
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    #[Test]
    public function password_reset_request_limit_is_shared_across_email_case_variants(): void
    {
        Notification::fake();
        $this->user();

        foreach (['ali@example.test', 'ALI@example.test', 'Ali@Example.test'] as $email) {
            $this->post(route('website.password.email'), ['email' => $email])->assertSessionHasNoErrors();
        }

        $this->post(route('website.password.email'), ['email' => 'aLi@example.test'])->assertSessionHasErrors('email');
    }

    // ───────────────────────── E2: Reset ─────────────────────────

    #[Test]
    public function a_valid_reset_sets_the_password_rotates_remember_token_and_is_audited(): void
    {
        $user = $this->user();
        $oldRemember = $user->remember_token;
        $token = Password::broker('users')->createToken($user);

        $this->reset('ali@example.test', $token)->assertRedirect(route('website.login'));

        $user->refresh();
        $this->assertTrue(\Hash::check('new-strong-password-1', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertSame(1, $this->audit('identity.password_reset'));
        // Email از قبل تأییدشده بود ⇒ رویداد تأیید تازه‌ای ثبت نمی‌شود.
        $this->assertSame(0, $this->audit('identity.email_verified'));

        $this->post(route('website.login.store'), ['email' => 'ali@example.test', 'password' => 'new-strong-password-1'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_reset_link_works_with_a_different_email_case_and_is_single_use(): void
    {
        $user = $this->user(['email' => 'Ali@Example.test']);
        $token = Password::broker('users')->createToken($user);

        $this->reset('ALI@EXAMPLE.TEST', $token)->assertRedirect(route('website.login'));
        $this->reset('ali@example.test', $token, 'another-strong-password-2')->assertSessionHasErrors('email');

        $this->assertTrue(\Hash::check('new-strong-password-1', $user->fresh()->password));
    }

    #[Test]
    public function a_successful_reset_proves_mailbox_control_and_verifies_an_unverified_email(): void
    {
        // سناریوی Pre-hijack: مهاجم با Email قربانی (تأییدنشده) ثبت‌نام کرده؛ قربانی با Reset مالک می‌شود.
        $user = $this->user(['password' => 'attacker-password', 'email_verified_at' => null]);
        $token = Password::broker('users')->createToken($user);

        $this->reset('ali@example.test', $token)->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(\Hash::check('attacker-password', $user->password));
        $this->assertSame(1, $this->audit('identity.email_verified'));
        $this->assertSame('password_reset', AuditLog::query()->where('action', 'identity.email_verified')->first()->after['method']);
    }

    #[Test]
    public function a_failed_reset_changes_nothing_and_does_not_verify(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $this->reset('ali@example.test', 'not-a-real-token')->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(\Hash::check('old-strong-password', $user->password));
        $this->assertSame(0, $this->audit('identity.password_reset'));
        $this->assertSame(0, $this->audit('identity.email_verified'));
    }

    #[Test]
    public function a_weak_or_unconfirmed_password_is_refused_and_the_token_stays_usable(): void
    {
        $user = $this->user();
        $token = Password::broker('users')->createToken($user);

        $this->post(route('website.password.store'), [
            'token' => $token, 'email' => 'ali@example.test', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->reset('ali@example.test', $token)->assertRedirect(route('website.login'));
    }

    #[Test]
    public function a_google_only_user_sets_a_first_password_via_reset_and_keeps_the_google_identity(): void
    {
        $user = $this->user(['password' => null]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1']);
        $token = Password::broker('users')->createToken($user);

        $this->reset('ali@example.test', $token)->assertRedirect(route('website.login'));

        $this->assertTrue(\Hash::check('new-strong-password-1', $user->fresh()->password));
        $this->assertSame(1, UserIdentity::query()->where('user_id', $user->id)->count());
        $this->assertTrue((bool) AuditLog::query()->where('action', 'identity.password_reset')->first()->after['first_password']);

        $this->post(route('website.login.store'), ['email' => 'ali@example.test', 'password' => 'new-strong-password-1'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    // ───────────────────────── E3: تأیید با لینک ─────────────────────────

    #[Test]
    public function link_verification_is_audited_once_and_repeating_it_adds_nothing(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();
        $this->actingAs($user->fresh())->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, $this->audit('identity.email_verified'));
        $this->assertSame('link', AuditLog::query()->where('action', 'identity.email_verified')->first()->after['method']);
    }

    // ───────────────────────── E4: ابطال Session ─────────────────────────

    #[Test]
    public function completing_a_reset_revokes_all_database_sessions_of_that_user_only(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->user();
        $other = $this->user(['email' => 'other@example.test']);

        foreach ([[$user->id, 's1'], [$user->id, 's2'], [$other->id, 's3']] as [$uid, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        }

        $this->assertSame(2, app(EmailAuthService::class)->revokeSessions($user));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    #[Test]
    public function session_revocation_is_a_safe_noop_without_the_database_driver(): void
    {
        config(['session.driver' => 'file']);

        $this->assertSame(0, app(EmailAuthService::class)->revokeSessions($this->user()));
    }
}
