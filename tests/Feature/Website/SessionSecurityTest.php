<?php

namespace Tests\Feature\Website;

use App\Channels\Website\Http\Middleware\EnforceSessionPolicy;
use App\Channels\Website\Support\DeviceLabel;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Core\Identity\SessionSecurityService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B2.5 — Session Security (docs/canonical/SESSION-SECURITY-CONTRACT.md).
 *
 * این تست‌ها با Session Driver = database و نشست واقعی (سطر در جدول sessions + Cookie) اجرا می‌شوند؛
 * actingAs عمداً استفاده نمی‌شود چون کلید ورود (login_web_*) را در payload نمی‌نویسد.
 */
class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    protected const SAFARI_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database', 'session.lifetime' => 120, 'session.absolute_lifetime' => 10080]);
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

    protected function googleOnlyUser(): User
    {
        $user = $this->user(['email' => 'g@example.test', 'password' => null]);
        UserIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-sub-1', 'provider_email' => $user->email]);

        return $user;
    }

    /** یک نشست واقعی (سطر database) می‌سازد؛ شناسه‌اش را برمی‌گرداند. */
    protected function seedSession(User $user, array $extra = [], array $row = [], bool $stamped = true): string
    {
        $id = $row['id'] ?? Str::random(40);

        $payload = [
            '_token' => Str::random(40),
            Auth::guard('web')->getName() => $user->id,
        ];

        if ($user->getAuthPassword()) {
            $payload['password_hash_web'] = $user->getAuthPassword();
        }

        if ($stamped) {
            $payload[EnforceSessionPolicy::STARTED_AT] = now()->getTimestamp();
            $payload[EnforceSessionPolicy::REMEMBERED] = false;
        }

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $row['user_id'] ?? $user->id,
            'ip_address' => $row['ip'] ?? '203.0.113.7',
            'user_agent' => $row['ua'] ?? self::CHROME_WIN,
            'payload' => base64_encode(serialize(array_merge($payload, $extra))),
            'last_activity' => $row['last'] ?? now()->getTimestamp(),
        ]);

        return $id;
    }

    protected function as(string $sessionId): static
    {
        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    protected function sessionIdsOf(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
    }

    protected function handleOf(string $sessionId): string
    {
        return app(SessionSecurityService::class)->handle($sessionId);
    }

    // ───────────────────────── فهرست نشست‌ها ─────────────────────────

    #[Test]
    public function the_profile_lists_active_sessions_and_marks_the_current_one(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $this->seedSession($user, row: ['ua' => self::SAFARI_IOS, 'ip' => '198.51.100.9']);

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('دستگاه‌ها و نشست‌های فعال')
            ->assertSee('Chrome · Windows')
            ->assertSee('Safari · iOS')
            ->assertSee('این دستگاه')
            ->assertSee('203.0.*.*')
            ->assertSee('198.51.*.*')
            ->assertDontSee('203.0.113.7')
            ->assertSee('خروج از همه‌ی دستگاه‌های دیگر');
    }

    #[Test]
    public function the_page_never_exposes_a_raw_session_id_only_an_opaque_handle(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $other = $this->seedSession($user);

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee($other)
            ->assertSee($this->handleOf($other))
            ->assertDontSee($this->handleOf($current)); // نشست فعلی دکمه‌ی «بستن» ندارد
    }

    #[Test]
    public function other_users_and_other_guards_sessions_are_never_listed(): void
    {
        $user = $this->user();
        $stranger = $this->user(['email' => 'stranger@example.test']);
        $current = $this->seedSession($user);

        // نشست Admin/Reseller با user_id برخوردکننده: Filament گارد پیش‌فرض را عوض می‌کند و user_id را پر می‌کند.
        $adminRow = $this->seedSession($user, ['login_admin_deadbeef' => $user->id], ['ua' => 'AdminBrowser Firefox/1 Linux', 'ip' => '192.0.2.99']);
        DB::table('sessions')->where('id', $adminRow)->update(['payload' => base64_encode(serialize(['login_admin_deadbeef' => $user->id]))]);
        $this->seedSession($stranger, row: ['ua' => 'StrangerBrowser Firefox/1 Linux', 'ip' => '192.0.2.50']);

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee('Firefox · Linux')
            ->assertDontSee('192.0.*.*');
    }

    #[Test]
    public function expired_sessions_are_not_listed(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $this->seedSession($user, row: ['ua' => self::SAFARI_IOS, 'last' => now()->subMinutes(121)->getTimestamp()]);

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee('Safari · iOS');
    }

    #[Test]
    public function the_service_lists_nothing_when_the_driver_cannot_list_sessions(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        config(['session.driver' => 'file']);

        $service = app(SessionSecurityService::class);
        $this->assertFalse($service->supported());
        $this->assertTrue($service->activeFor($user, $current)->isEmpty());
        $this->assertSame(0, $service->revokeOthers($user, $current));
        $this->assertSame(0, $service->revokeAll($user));
    }

    #[Test]
    public function the_sessions_card_is_not_rendered_without_the_database_driver(): void
    {
        config(['session.driver' => 'array']);

        $this->actingAs($this->user())->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee('دستگاه‌ها و نشست‌های فعال')
            ->assertSee('روش‌های ورود');
    }

    // ───────────────────────── پایان نشست‌های دیگر ─────────────────────────

    #[Test]
    public function the_session_routes_require_login(): void
    {
        foreach (['identity.sessions.revoke', 'identity.sessions.revoke-others', 'identity.password.update'] as $name) {
            $this->post(route('website.'.$name))->assertRedirect(route('website.login'));
        }
    }

    #[Test]
    public function revoking_others_requires_the_current_password_and_changes_nothing_when_wrong(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $other = $this->seedSession($user);

        $this->as($current)->post(route('website.identity.sessions.revoke-others'))->assertSessionHasErrors('sessions');
        $this->as($current)->post(route('website.identity.sessions.revoke-others'), ['current_password' => 'wrong'])->assertSessionHasErrors('sessions');

        $this->assertEqualsCanonicalizing([$current, $other], $this->sessionIdsOf($user));
        $this->assertFalse(AuditLog::query()->where('action', 'identity.sessions_revoked')->exists());
    }

    #[Test]
    public function revoking_others_keeps_the_current_session_and_is_audited_without_pii(): void
    {
        $user = $this->user();
        $stranger = $this->user(['email' => 'stranger@example.test']);
        $current = $this->seedSession($user);
        $this->seedSession($user);
        $this->seedSession($user, row: ['ua' => self::SAFARI_IOS]);
        $strangerSession = $this->seedSession($stranger);

        $this->as($current)->post(route('website.identity.sessions.revoke-others'), ['current_password' => 'a-strong-password'])
            ->assertRedirect(route('website.identity.profile.show'))
            ->assertSessionHas('status');

        $this->assertSame([$current], $this->sessionIdsOf($user));
        $this->assertSame([$strangerSession], $this->sessionIdsOf($stranger));

        $log = AuditLog::query()->where('action', 'identity.sessions_revoked')->firstOrFail();
        $this->assertSame(['scope' => 'others', 'count' => 2, 'reason' => 'user_request'], $log->after);
        $this->assertStringNotContainsString('Chrome', json_encode($log->after));
    }

    #[Test]
    public function a_google_only_user_can_revoke_others_without_a_password(): void
    {
        $user = $this->googleOnlyUser();
        $current = $this->seedSession($user);
        $this->seedSession($user);

        $this->as($current)->post(route('website.identity.sessions.revoke-others'))->assertSessionHasNoErrors();

        $this->assertSame([$current], $this->sessionIdsOf($user));
    }

    #[Test]
    public function one_session_can_be_closed_by_its_handle(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $victim = $this->seedSession($user);
        $keep = $this->seedSession($user);

        $this->as($current)->post(route('website.identity.sessions.revoke'), [
            'session' => $this->handleOf($victim),
            'current_password' => 'a-strong-password',
        ])->assertSessionHas('status');

        $this->assertEqualsCanonicalizing([$current, $keep], $this->sessionIdsOf($user));
        $this->assertSame('one', AuditLog::query()->where('action', 'identity.sessions_revoked')->firstOrFail()->after['scope']);
    }

    #[Test]
    public function the_current_session_and_foreign_handles_cannot_be_closed_through_the_revoke_route(): void
    {
        $user = $this->user();
        $stranger = $this->user(['email' => 'stranger@example.test']);
        $current = $this->seedSession($user);
        $strangerSession = $this->seedSession($stranger);

        foreach ([$this->handleOf($current), $this->handleOf($strangerSession), $strangerSession, 'nonsense'] as $handle) {
            $this->as($current)->post(route('website.identity.sessions.revoke'), ['session' => $handle, 'current_password' => 'a-strong-password'])
                ->assertSessionHasErrors('sessions');
        }

        $this->assertSame([$current], $this->sessionIdsOf($user));
        $this->assertSame([$strangerSession], $this->sessionIdsOf($stranger));
    }

    // ───────────────────────── تغییر رمز ─────────────────────────

    #[Test]
    public function changing_the_password_revokes_other_sessions_and_keeps_this_one(): void
    {
        $user = $this->user();
        $oldToken = $user->remember_token;
        $current = $this->seedSession($user);
        $this->seedSession($user);
        $this->seedSession($user);

        $this->as($current)->post(route('website.identity.password.update'), [
            'existing_password' => 'a-strong-password',
            'password' => 'Brand-New-Pass-9731',
            'password_confirmation' => 'Brand-New-Pass-9731',
        ])->assertRedirect(route('website.identity.profile.show'))->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('Brand-New-Pass-9731', $user->password));
        $this->assertNotSame($oldToken, $user->remember_token);
        $this->assertSame([$current], $this->sessionIdsOf($user));

        $this->assertSame('password_changed', AuditLog::query()->where('action', 'identity.sessions_revoked')->firstOrFail()->after['reason']);
        $audit = AuditLog::query()->where('action', 'identity.password_changed')->firstOrFail();
        $this->assertStringNotContainsString('Brand-New', json_encode($audit->after));
    }

    #[Test]
    public function the_current_device_stays_logged_in_after_a_password_change(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);

        $this->as($current)->post(route('website.identity.password.update'), [
            'existing_password' => 'a-strong-password',
            'password' => 'Brand-New-Pass-9731',
            'password_confirmation' => 'Brand-New-Pass-9731',
        ]);

        $this->as($current)->get(route('website.identity.profile.show'))->assertOk();
    }

    #[Test]
    public function a_wrong_current_password_changes_nothing_and_is_audited(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $other = $this->seedSession($user);

        $this->as($current)->post(route('website.identity.password.update'), [
            'existing_password' => 'not-my-password',
            'password' => 'Brand-New-Pass-9731',
            'password_confirmation' => 'Brand-New-Pass-9731',
        ])->assertSessionHasErrors('existing_password');

        $this->assertTrue(Hash::check('a-strong-password', $user->fresh()->password));
        $this->assertContains($other, $this->sessionIdsOf($user));
        $this->assertSame('wrong_password', AuditLog::query()->where('action', 'identity.password_change_rejected')->firstOrFail()->after['reason']);
    }

    #[Test]
    public function the_same_weak_or_unconfirmed_password_is_refused(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);
        $other = $this->seedSession($user);
        $url = route('website.identity.password.update');

        $this->as($current)->post($url, ['existing_password' => 'a-strong-password', 'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password'])
            ->assertSessionHasErrors('password');
        $this->as($current)->post($url, ['existing_password' => 'a-strong-password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->as($current)->post($url, ['existing_password' => 'a-strong-password', 'password' => 'Brand-New-Pass-9731', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('a-strong-password', $user->fresh()->password));
        $this->assertContains($other, $this->sessionIdsOf($user));
    }

    #[Test]
    public function a_google_only_user_cannot_use_the_change_route_and_gets_no_change_form(): void
    {
        $user = $this->googleOnlyUser();
        $current = $this->seedSession($user);

        $this->as($current)->post(route('website.identity.password.update'), [
            'existing_password' => 'anything',
            'password' => 'Brand-New-Pass-9731',
            'password_confirmation' => 'Brand-New-Pass-9731',
        ])->assertSessionHasErrors();

        $this->assertNull($user->fresh()->getRawOriginal('password'));

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertDontSee('تغییر رمز عبور')
            ->assertSee('تعیین رمز عبور');
    }

    #[Test]
    public function the_profile_offers_a_change_form_to_a_user_with_a_password(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);

        $this->as($current)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSee('تغییر رمز عبور')
            ->assertSee(route('website.identity.password.update'), false);
    }

    #[Test]
    public function setting_a_first_password_revokes_other_sessions(): void
    {
        $user = $this->googleOnlyUser();
        $current = $this->seedSession($user);
        $this->seedSession($user);

        $this->as($current)->post(route('website.identity.password.set'), [
            'password' => 'Brand-New-Pass-9731',
            'password_confirmation' => 'Brand-New-Pass-9731',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$current], $this->sessionIdsOf($user));
        $this->assertSame('password_set', AuditLog::query()->where('action', 'identity.sessions_revoked')->firstOrFail()->after['reason']);
    }

    #[Test]
    public function the_change_routes_are_throttled_per_user(): void
    {
        $user = $this->user();
        $current = $this->seedSession($user);

        for ($i = 0; $i < 5; $i++) {
            $this->as($current)->post(route('website.identity.password.update'), ['existing_password' => 'bad', 'password' => 'x', 'password_confirmation' => 'x']);
        }

        $this->as($current)->post(route('website.identity.password.update'), ['existing_password' => 'bad', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertStatus(429);
    }

    // ───────────────────────── AuthenticateSession ─────────────────────────

    #[Test]
    public function a_session_with_a_stale_password_hash_is_logged_out(): void
    {
        $user = $this->user();
        $stale = $this->seedSession($user, ['password_hash_web' => 'hash-from-before-the-change']);

        $this->as($stale)->get(route('website.identity.profile.show'))->assertRedirect(route('website.login'));
    }

    #[Test]
    public function a_session_with_the_current_password_hash_stays_logged_in(): void
    {
        $user = $this->user();

        $this->as($this->seedSession($user))->get(route('website.identity.profile.show'))->assertOk();
    }

    // ───────────────────────── سیاست نشست ─────────────────────────

    #[Test]
    public function a_user_who_became_inactive_loses_the_live_session_and_it_is_audited(): void
    {
        $user = $this->user();
        $id = $this->seedSession($user);
        $user->forceFill(['status' => 'blocked'])->save();

        $this->as($id)->get(route('website.home'))->assertRedirect(route('website.login'));

        $this->assertSame([], $this->sessionIdsOf($user));
        $this->assertSame('user_not_active', AuditLog::query()->where('action', 'identity.session_terminated')->firstOrFail()->after['reason']);
    }

    #[Test]
    public function ending_one_session_by_policy_does_not_touch_the_other_devices_of_the_user(): void
    {
        $user = $this->user();
        $token = $user->remember_token;
        $expired = $this->seedSession($user, [EnforceSessionPolicy::STARTED_AT => now()->subDays(8)->getTimestamp()]);
        $keep = $this->seedSession($user);

        $this->as($expired)->get(route('website.home'))->assertRedirect(route('website.login'));

        $this->assertSame($token, $user->fresh()->remember_token, 'remember_token must not rotate: other devices keep their remember cookie');
        $this->assertSame([$keep], $this->sessionIdsOf($user));
    }

    #[Test]
    public function a_session_past_the_absolute_lifetime_is_ended_even_when_active(): void
    {
        $user = $this->user();
        $old = $this->seedSession($user, [EnforceSessionPolicy::STARTED_AT => now()->subMinutes(10081)->getTimestamp()]);

        $this->as($old)->get(route('website.identity.profile.show'))->assertRedirect(route('website.login'))
            ->assertSessionHasErrors('email');

        $this->assertSame('absolute_timeout', AuditLog::query()->where('action', 'identity.session_terminated')->firstOrFail()->after['reason']);
    }

    #[Test]
    public function a_session_inside_the_absolute_lifetime_is_untouched(): void
    {
        $user = $this->user();
        $fresh = $this->seedSession($user, [EnforceSessionPolicy::STARTED_AT => now()->subMinutes(10079)->getTimestamp()]);

        $this->as($fresh)->get(route('website.identity.profile.show'))->assertOk();
        $this->assertFalse(AuditLog::query()->where('action', 'identity.session_terminated')->exists());
    }

    #[Test]
    public function remember_me_sessions_and_a_zero_setting_are_exempt_from_the_absolute_lifetime(): void
    {
        $user = $this->user();
        $remembered = $this->seedSession($user, [EnforceSessionPolicy::STARTED_AT => now()->subDays(60)->getTimestamp(), EnforceSessionPolicy::REMEMBERED => true]);
        $this->as($remembered)->get(route('website.identity.profile.show'))->assertOk();

        config(['session.absolute_lifetime' => 0]);
        $plain = $this->seedSession($user, [EnforceSessionPolicy::STARTED_AT => now()->subDays(60)->getTimestamp()]);
        $this->as($plain)->get(route('website.identity.profile.show'))->assertOk();
    }

    #[Test]
    public function a_legacy_session_without_a_stamp_is_adopted_not_logged_out(): void
    {
        $user = $this->user();
        $legacy = $this->seedSession($user, stamped: false);

        $this->as($legacy)->get(route('website.identity.profile.show'))
            ->assertOk()
            ->assertSessionHas(EnforceSessionPolicy::STARTED_AT);
    }

    #[Test]
    public function the_policy_does_nothing_for_visitors(): void
    {
        $this->get(route('website.home'))->assertOk();
        $this->get(route('website.login'))->assertOk();
        $this->assertFalse(AuditLog::query()->where('action', 'identity.session_terminated')->exists());
    }

    #[Test]
    public function a_successful_login_stamps_the_session_start(): void
    {
        config(['session.driver' => 'array']);
        $this->user();

        $this->post(route('website.login.store'), ['email' => 'member@example.test', 'password' => 'a-strong-password'])
            ->assertSessionHas(EnforceSessionPolicy::STARTED_AT)
            ->assertSessionHas(EnforceSessionPolicy::REMEMBERED, false);
    }

    #[Test]
    public function a_remember_me_login_is_marked_as_remembered(): void
    {
        config(['session.driver' => 'array']);
        $this->user();

        $this->post(route('website.login.store'), ['email' => 'member@example.test', 'password' => 'a-strong-password', 'remember' => '1'])
            ->assertSessionHas(EnforceSessionPolicy::REMEMBERED, true);
    }

    // ───────────────────────── Core و پیکربندی ─────────────────────────

    #[Test]
    public function revoke_all_keeps_provable_other_guard_sessions_but_removes_unreadable_rows(): void
    {
        $user = $this->user();
        $web = $this->seedSession($user);
        $unreadable = $this->seedSession($user);
        DB::table('sessions')->where('id', $unreadable)->update(['payload' => '']);
        $admin = $this->seedSession($user);
        DB::table('sessions')->where('id', $admin)->update(['payload' => base64_encode(serialize(['login_admin_deadbeef' => $user->id]))]);

        $this->assertSame(2, app(SessionSecurityService::class)->revokeAll($user));
        $this->assertSame([$admin], $this->sessionIdsOf($user));
        $this->assertNotContains($web, $this->sessionIdsOf($user));
    }

    #[Test]
    public function the_handle_is_a_keyed_hash_not_the_session_id(): void
    {
        $service = app(SessionSecurityService::class);

        $this->assertNotSame('abc', $service->handle('abc'));
        $this->assertSame(32, strlen($service->handle('abc')));
        $this->assertSame($service->handle('abc'), $service->handle('abc'));
        $this->assertNotSame($service->handle('abc'), $service->handle('abd'));
    }

    #[Test]
    public function the_remember_me_cookie_lifetime_is_bounded(): void
    {
        $this->assertSame(43200, config('auth.guards.web.remember'));

        $duration = (new \ReflectionProperty(SessionGuard::class, 'rememberDuration'))
            ->getValue(Auth::guard('web'));
        $this->assertSame(43200, $duration);
    }

    #[Test]
    public function device_labels_are_short_and_ips_are_masked(): void
    {
        $this->assertSame('Chrome · Windows', DeviceLabel::describe(self::CHROME_WIN));
        $this->assertSame('Safari · iOS', DeviceLabel::describe(self::SAFARI_IOS));
        $this->assertSame('Edge · Windows', DeviceLabel::describe('Mozilla/5.0 (Windows NT 10.0) Chrome/126 Safari/537.36 Edg/126.0'));
        $this->assertSame('Firefox · Linux', DeviceLabel::describe('Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0'));
        $this->assertSame('دستگاه ناشناخته', DeviceLabel::describe(null));
        $this->assertSame('دستگاه ناشناخته', DeviceLabel::describe('curl-ish'));

        $this->assertSame('203.0.*.*', DeviceLabel::maskIp('203.0.113.7'));
        $this->assertSame('2001:db8:*', DeviceLabel::maskIp('2001:db8:85a3::8a2e:370:7334'));
        $this->assertSame('—', DeviceLabel::maskIp(null));
    }
}
