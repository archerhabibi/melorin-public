<?php

namespace Tests\Feature\Website;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerWebsiteSetting;
use App\Models\User;
use App\Services\Resellers\Domains\DnsTxtResolver;
use App\Services\Resellers\Domains\ResellerDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * B6.1 — Custom Domain: سرویس (نرمال‌سازی/یکتایی/تأیید/حذف/Audit)، مسیریابی بر پایه‌ی Host،
 * URLهای خروجی، Endpoint مجوز TLS و UI مدیریت.
 */
class CustomDomainTest extends TestCase
{
    use FakesTelegram, RefreshDatabase;

    /** @var array<string, list<string>> */
    private array $dnsRecords = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        config(['app.url' => 'https://platform.test.melorin.io']);

        $records = &$this->dnsRecords;
        $this->app->bind(DnsTxtResolver::class, function () use (&$records) {
            return new class($records) implements DnsTxtResolver
        {
            public function __construct(private array &$records) {}

            public function txt(string $name): array
            {
                return $this->records[$name] ?? [];
            }
            };
        });
    }

    private function domains(): ResellerDomainService
    {
        return app(ResellerDomainService::class);
    }

    private function owner(Reseller $reseller): User
    {
        $owner = User::factory()->create();
        ResellerAdmin::create(['reseller_id' => $reseller->id, 'user_id' => $owner->id, 'role' => 'owner']);

        return $owner;
    }

    /** نماینده‌ی فعال با دامنه‌ی تأییدشده. */
    private function verifiedStore(string $domain = 'shop.example.com', array $attrs = []): Reseller
    {
        $reseller = Reseller::factory()->create($attrs + ['status' => 'active']);
        $owner = $this->owner($reseller);
        $this->domains()->set($reseller, $owner, $domain);
        $this->dnsRecords['_melorin-verify.'.$domain] = [ResellerWebsiteSetting::forReseller($reseller)->custom_domain_token];
        $this->assertTrue($this->domains()->verify($reseller, $owner));

        return $reseller;
    }

    // ─── نرمال‌سازی ────────────────────────────────────────────

    #[Test]
    public function it_normalizes_valid_input(): void
    {
        $s = $this->domains();

        $this->assertSame('shop.example.com', $s->normalize('  HTTPS://Shop.Example.com/path?x=1#h '));
        $this->assertSame('shop.example.com', $s->normalize('shop.example.com.'));
        $this->assertSame('xn--mnchen-3ya.de', $s->normalize('xn--mnchen-3ya.de'));
    }

    public static function invalidDomains(): array
    {
        return [
            'empty' => [''],
            'single label' => ['localhost'],
            'ip' => ['192.168.1.10'],
            'port' => ['shop.example.com:8080'],
            'userinfo' => ['a@shop.example.com'],
            'wildcard' => ['*.example.com'],
            'underscore' => ['sho_p.example.com'],
            'leading hyphen' => ['-shop.example.com'],
            'reserved tld' => ['shop.internal'],
            'local tld' => ['printer.local'],
            'numeric tld' => ['shop.123'],
            'platform itself' => ['platform.test.melorin.io'],
            'platform subdomain' => ['evil.platform.test.melorin.io'],
            'too long label' => [str_repeat('a', 64).'.example.com'],
        ];
    }

    #[Test]
    #[DataProvider('invalidDomains')]
    public function it_rejects_invalid_domains(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->domains()->normalize($input);
    }

    // ─── ثبت / یکتایی / Audit ───────────────────────────────────

    #[Test]
    public function setting_a_domain_creates_pending_state_with_token_and_audit(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $owner = $this->owner($reseller);

        $this->assertTrue($this->domains()->set($reseller, $owner, 'Shop.Example.com'));

        $state = $this->domains()->state($reseller);
        $this->assertSame('shop.example.com', $state['domain']);
        $this->assertSame('pending', $state['status']);
        $this->assertFalse($state['verified']);
        $this->assertSame('_melorin-verify.shop.example.com', $state['txt_name']);
        $this->assertSame(40, strlen($state['txt_value']));

        $log = AuditLog::query()->where('action', 'reseller.domain.set')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($state['txt_value'], json_encode($log->toArray()), 'توکن نباید در Audit بیاید');
    }

    #[Test]
    public function setting_the_same_domain_again_changes_nothing_and_writes_no_audit(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $owner = $this->owner($reseller);
        $this->domains()->set($reseller, $owner, 'shop.example.com');
        $token = $this->domains()->state($reseller)['txt_value'];

        $this->assertFalse($this->domains()->set($reseller, $owner, 'SHOP.example.com'));

        $this->assertSame($token, $this->domains()->state($reseller)['txt_value']);
        $this->assertSame(1, AuditLog::query()->where('action', 'reseller.domain.set')->count());
    }

    #[Test]
    public function a_domain_is_unique_across_the_platform_without_leaking_the_owner(): void
    {
        $this->verifiedStore('shop.example.com');
        $other = Reseller::factory()->create(['status' => 'active']);

        try {
            $this->domains()->set($other, $this->owner($other), 'shop.example.com');
            $this->fail('باید رد می‌شد');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('shop', strtolower($e->getMessage()));
        }

        $this->assertNull($this->domains()->state($other)['domain']);
    }

    #[Test]
    public function changing_the_domain_revokes_verification_and_rotates_the_token(): void
    {
        $reseller = $this->verifiedStore('shop.example.com');
        $oldToken = $this->domains()->state($reseller)['txt_value'];

        $this->domains()->set($reseller, $this->owner($reseller), 'store.example.org');

        $state = $this->domains()->state($reseller);
        $this->assertSame('pending', $state['status']);
        $this->assertNotSame($oldToken, $state['txt_value']);
        $this->assertNull($this->domains()->resellerForHost('shop.example.com'));
        $this->assertNull($this->domains()->resellerForHost('store.example.org'), 'تا تأیید نشده مسیریابی نمی‌شود');
    }

    // ─── تأیید DNS ─────────────────────────────────────────────

    #[Test]
    public function verification_needs_the_exact_token_in_the_txt_record(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $owner = $this->owner($reseller);
        $this->domains()->set($reseller, $owner, 'shop.example.com');

        $this->assertFalse($this->domains()->verify($reseller, $owner), 'بدون رکورد');

        $this->dnsRecords['_melorin-verify.shop.example.com'] = ['wrong-token'];
        $this->assertFalse($this->domains()->verify($reseller, $owner), 'توکن اشتباه');
        $this->assertNotNull($this->domains()->state($reseller)['checked_at']);
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller.domain.verified')->count());

        $token = $this->domains()->state($reseller)['txt_value'];
        $this->dnsRecords['_melorin-verify.shop.example.com'] = ['other', '"'.$token.'"'];

        $this->assertTrue($this->domains()->verify($reseller, $owner));
        $this->assertTrue($this->domains()->state($reseller)['verified']);
        $this->assertSame(1, AuditLog::query()->where('action', 'reseller.domain.verified')->count());
    }

    #[Test]
    public function verify_without_a_domain_is_a_clear_error_and_creates_no_row(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->domains()->verify($reseller, $this->owner($reseller));
        } finally {
            $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
        }
    }

    #[Test]
    public function removing_clears_everything_and_stops_routing(): void
    {
        $reseller = $this->verifiedStore();

        $this->assertTrue($this->domains()->remove($reseller, $this->owner($reseller)));
        $this->assertFalse($this->domains()->remove($reseller, $this->owner($reseller)));

        $setting = ResellerWebsiteSetting::forReseller($reseller);
        $this->assertNull($setting->custom_domain);
        $this->assertNull($setting->custom_domain_token);
        $this->assertNull($this->domains()->resellerForHost('shop.example.com'));
        $this->assertSame(1, AuditLog::query()->where('action', 'reseller.domain.removed')->count());
    }

    // ─── مسیریابی بر پایه‌ی Host ─────────────────────────────────

    #[Test]
    public function a_verified_domain_serves_the_reseller_store_at_the_root(): void
    {
        $reseller = $this->verifiedStore();
        ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->update(['display_name' => 'فروشگاه نمونه']);

        $this->get('https://shop.example.com/')
            ->assertOk()
            ->assertSee('فروشگاه نمونه');
    }

    #[Test]
    public function links_on_a_custom_domain_stay_on_that_domain_without_the_store_prefix(): void
    {
        $this->verifiedStore();

        $html = $this->get('https://shop.example.com/login')->assertOk()->getContent();

        $this->assertStringContainsString('https://shop.example.com/register', $html);
        $this->assertStringNotContainsString('/store/', $html);
        $this->assertStringNotContainsString('platform.test.melorin.io', $html);
    }

    #[Test]
    public function the_store_prefix_and_panel_paths_are_not_served_on_a_custom_domain(): void
    {
        $reseller = $this->verifiedStore();

        $this->get('https://shop.example.com/store/'.$reseller->slug)->assertNotFound();
        $this->get('https://shop.example.com/'.$reseller->slug.'/login')->assertNotFound();
    }

    #[Test]
    public function an_unverified_or_unknown_host_is_not_routed_to_a_store(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $this->domains()->set($reseller, $this->owner($reseller), 'pending.example.com');
        ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->update(['display_name' => 'نباید دیده شود']);

        $this->get('https://pending.example.com/')->assertOk()->assertDontSee('نباید دیده شود');
        $this->get('https://unknown.example.net/')->assertOk()->assertDontSee('نباید دیده شود');
    }

    #[Test]
    public function the_platform_host_is_untouched_and_the_store_prefix_still_works(): void
    {
        $reseller = $this->verifiedStore();

        $this->get('https://platform.test.melorin.io/store/'.$reseller->slug)->assertOk();
        $this->get('https://platform.test.melorin.io/')->assertOk();
    }

    #[Test]
    public function an_inactive_reseller_domain_is_404(): void
    {
        $reseller = $this->verifiedStore();
        $reseller->update(['status' => 'inactive']);

        $this->get('https://shop.example.com/')->assertNotFound();
    }

    #[Test]
    public function the_feature_flag_turns_routing_off(): void
    {
        $this->verifiedStore();
        config(['melorin.domains.enabled' => false]);

        $this->get('https://shop.example.com/')->assertOk()->assertDontSee('نمایندگی');
        $this->get('https://shop.example.com/login')->assertOk();
    }

    #[Test]
    public function url_generation_is_reset_after_a_custom_domain_request(): void
    {
        $reseller = $this->verifiedStore();

        $this->get('https://shop.example.com/')->assertOk();

        // (Host را Laravel از آخرین Request می‌گیرد؛ آنچه باید برگردد پیشوند /store/{slug} است، نه Host.)
        $this->assertStringEndsWith('/store/'.$reseller->slug, route('website.store.home', $reseller->slug));
        $this->assertStringEndsWith('/store/'.$reseller->slug.'/login', route('website.store.login', $reseller->slug));
    }

    #[Test]
    public function guests_hitting_a_protected_page_on_a_custom_domain_go_to_that_domains_login(): void
    {
        $this->verifiedStore();

        $this->get('https://shop.example.com/dashboard')->assertRedirect('https://shop.example.com/login');
    }

    #[Test]
    public function two_domains_serve_two_different_stores_without_mixing(): void
    {
        $a = $this->verifiedStore('a.example.com');
        $b = $this->verifiedStore('b.example.com');
        ResellerWebsiteSetting::query()->where('reseller_id', $a->id)->update(['display_name' => 'برند الف']);
        ResellerWebsiteSetting::query()->where('reseller_id', $b->id)->update(['display_name' => 'برند ب']);

        $this->get('https://a.example.com/')->assertSee('برند الف')->assertDontSee('برند ب');
        $this->get('https://b.example.com/')->assertSee('برند ب')->assertDontSee('برند الف');
    }

    // ─── Endpoint مجوز TLS ─────────────────────────────────────

    #[Test]
    public function the_tls_ask_endpoint_allows_only_verified_active_domains(): void
    {
        $reseller = $this->verifiedStore();
        $pending = Reseller::factory()->create(['status' => 'active']);
        $this->domains()->set($pending, $this->owner($pending), 'pending.example.com');

        $this->get('/health/domain-allowed?domain=shop.example.com')->assertOk();
        $this->get('/health/domain-allowed?domain=SHOP.example.com.')->assertOk();
        $this->get('/health/domain-allowed?domain=pending.example.com')->assertNotFound();
        $this->get('/health/domain-allowed?domain=nope.example.com')->assertNotFound();
        $this->get('/health/domain-allowed')->assertNotFound();

        $reseller->update(['status' => 'inactive']);
        $this->get('/health/domain-allowed?domain=shop.example.com')->assertNotFound();
    }

    #[Test]
    public function the_tls_ask_endpoint_requires_the_token_when_configured(): void
    {
        $this->verifiedStore();
        config(['melorin.domains.ask_token' => 's3cret']);

        $this->get('/health/domain-allowed?domain=shop.example.com')->assertForbidden();
        $this->get('/health/domain-allowed?domain=shop.example.com&token=bad')->assertForbidden();
        $this->get('/health/domain-allowed?domain=shop.example.com&token=s3cret')->assertOk();
    }

    // ─── UI مدیریت ─────────────────────────────────────────────

    #[Test]
    public function the_owner_sets_and_verifies_a_domain_from_the_management_page(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $owner = $this->owner($reseller);

        $this->actingAs($owner)->get(route('website.store.manage.domain', $reseller->slug))->assertOk()->assertSee('ثبت دامنه');

        $this->actingAs($owner)->post(route('website.store.manage.domain.save', $reseller->slug), ['domain' => 'shop.example.com'])
            ->assertSessionHas('status');

        $token = $this->domains()->state($reseller)['txt_value'];
        $this->actingAs($owner)->get(route('website.store.manage.domain', $reseller->slug))
            ->assertSee('_melorin-verify.shop.example.com')->assertSee($token);

        $this->actingAs($owner)->post(route('website.store.manage.domain.verify', $reseller->slug))
            ->assertSessionHasErrors('domain');

        $this->dnsRecords['_melorin-verify.shop.example.com'] = [$token];
        $this->actingAs($owner)->post(route('website.store.manage.domain.verify', $reseller->slug))
            ->assertSessionHas('status');

        $this->assertTrue($this->domains()->state($reseller)['verified']);
    }

    #[Test]
    public function invalid_input_shows_a_field_error_and_saves_nothing(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);

        $this->actingAs($this->owner($reseller))
            ->post(route('website.store.manage.domain.save', $reseller->slug), ['domain' => '192.168.0.1'])
            ->assertSessionHasErrors('domain');

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
    }

    #[Test]
    public function non_admins_and_guests_cannot_manage_domains(): void
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $stranger = User::factory()->create();
        $url = route('website.store.manage.domain.save', $reseller->slug);

        $this->post($url, ['domain' => 'shop.example.com'])->assertRedirect();
        $this->actingAs($stranger)->get(route('website.store.manage.domain', $reseller->slug))->assertForbidden();
        $this->actingAs($stranger)->post($url, ['domain' => 'shop.example.com'])->assertForbidden();
        $this->actingAs($stranger)->post(route('website.store.manage.domain.verify', $reseller->slug))->assertForbidden();
        $this->actingAs($stranger)->post(route('website.store.manage.domain.remove', $reseller->slug))->assertForbidden();

        $this->assertNull(ResellerWebsiteSetting::forReseller($reseller));
    }

    #[Test]
    public function an_admin_of_another_store_cannot_touch_this_stores_domain(): void
    {
        $mine = Reseller::factory()->create(['status' => 'active']);
        $theirs = $this->verifiedStore('theirs.example.com');

        $this->actingAs($this->owner($mine))
            ->post(route('website.store.manage.domain.remove', $theirs->slug))
            ->assertForbidden();

        $this->assertTrue($this->domains()->state($theirs)['verified']);
    }

    #[Test]
    public function google_login_is_hidden_and_blocked_on_a_custom_domain(): void
    {
        config(['services.google.enabled' => true, 'services.google.client_id' => 'x', 'services.google.client_secret' => 'y']);
        $this->verifiedStore();

        $this->get('https://shop.example.com/login')->assertOk()->assertDontSee('/auth/google');
        $this->get('https://shop.example.com/auth/google')->assertNotFound();
    }

    #[Test]
    public function the_migration_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_10_08_000001_add_custom_domain_to_reseller_website_settings.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('reseller_website_settings', 'custom_domain'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('reseller_website_settings', 'custom_domain_claimed_at'));
    }

    // ─── B6.1 تکمیل: بازبررسی، انقضای ادعا، کش، لغو ادمین ──────────────

    private function pendingStore(string $domain = 'shop.example.com'): Reseller
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);
        $this->domains()->set($reseller, $this->owner($reseller), $domain);

        return $reseller;
    }

    private function setting(Reseller $reseller): ResellerWebsiteSetting
    {
        return ResellerWebsiteSetting::forReseller($reseller)->refresh();
    }

    private function backdate(Reseller $reseller, array $columns): void
    {
        ResellerWebsiteSetting::query()->where('reseller_id', $reseller->id)->update($columns);
    }

    #[Test]
    public function the_scheduler_auto_verifies_a_pending_domain_once_the_txt_appears(): void
    {
        $r = $this->pendingStore();

        $this->assertSame(0, $this->domains()->runChecks()['verified']);
        $this->assertSame('pending', $this->setting($r)->custom_domain_status);

        $this->dnsRecords['_melorin-verify.shop.example.com'] = [$this->setting($r)->custom_domain_token];
        $summary = $this->domains()->runChecks();

        $this->assertSame(1, $summary['verified']);
        $this->assertSame('verified', $this->setting($r)->custom_domain_status);
        $this->assertNotNull($this->setting($r)->custom_domain_verified_at);
        $this->assertTrue(AuditLog::where('action', 'reseller.domain.verified')->where('actor_type', 'system')->exists());
        $this->get('http://shop.example.com/')->assertOk();
    }

    #[Test]
    public function a_stale_pending_claim_expires_and_audits(): void
    {
        $r = $this->pendingStore();
        $this->backdate($r, ['custom_domain_claimed_at' => now()->subHours(73)]);

        $summary = $this->domains()->runChecks();

        $this->assertSame(1, $summary['expired']);
        $this->assertNull($this->setting($r)->custom_domain);
        $this->assertNull($this->setting($r)->custom_domain_token);
        $this->assertTrue(AuditLog::where('action', 'reseller.domain.expired')->exists());
    }

    #[Test]
    public function a_fresh_pending_claim_is_kept_and_blocks_others(): void
    {
        $this->pendingStore();
        $other = Reseller::factory()->create(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->domains()->set($other, $this->owner($other), 'shop.example.com');
    }

    #[Test]
    public function a_stale_pending_claim_can_be_taken_over_by_another_reseller(): void
    {
        $squatter = $this->pendingStore();
        $this->backdate($squatter, ['custom_domain_claimed_at' => now()->subHours(100)]);
        $real = Reseller::factory()->create(['status' => 'active']);

        $this->assertTrue($this->domains()->set($real, $this->owner($real), 'shop.example.com'));

        $this->assertNull($this->setting($squatter)->custom_domain);
        $this->assertSame('shop.example.com', $this->setting($real)->custom_domain);
        $this->assertTrue(AuditLog::where('action', 'reseller.domain.expired')->exists());
    }

    #[Test]
    public function a_verified_domain_is_never_taken_over_even_if_old(): void
    {
        $owner = $this->verifiedStore();
        $this->backdate($owner, ['custom_domain_claimed_at' => now()->subDays(400)]);
        $other = Reseller::factory()->create(['status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        $this->domains()->set($other, $this->owner($other), 'shop.example.com');
    }

    #[Test]
    public function a_verified_domain_is_only_rechecked_after_the_interval(): void
    {
        $r = $this->verifiedStore();
        $this->backdate($r, ['custom_domain_checked_at' => now()->subMinutes(5)]);

        $this->assertSame(0, $this->domains()->runChecks()['checked']);

        $this->backdate($r, ['custom_domain_checked_at' => now()->subHours(7)]);
        $summary = $this->domains()->runChecks();

        $this->assertSame(1, $summary['confirmed']);
        $this->assertTrue($this->setting($r)->custom_domain_checked_at->gt(now()->subMinute()));
    }

    #[Test]
    public function a_missing_txt_within_the_grace_period_keeps_the_domain_verified(): void
    {
        $r = $this->verifiedStore();
        unset($this->dnsRecords['_melorin-verify.shop.example.com']);
        $this->backdate($r, ['custom_domain_checked_at' => now()->subHours(7)]);

        $summary = $this->domains()->runChecks();

        $this->assertSame(0, $summary['lost']);
        $this->assertSame('verified', $this->setting($r)->custom_domain_status);
        $this->get('http://shop.example.com/')->assertOk();
    }

    #[Test]
    public function a_txt_missing_beyond_the_grace_period_demotes_the_domain_and_stops_serving(): void
    {
        $r = $this->verifiedStore();
        // `/manage/domain` فقط روی فروشگاه (بازنویسی‌شده) وجود دارد: مهمان ⇒ Redirect به ورود؛ بدون بازنویسی ⇒ 404
        $this->get('http://shop.example.com/manage/domain')->assertRedirect();
        unset($this->dnsRecords['_melorin-verify.shop.example.com']);
        $this->backdate($r, ['custom_domain_checked_at' => now()->subHours(80)]);

        $summary = $this->domains()->runChecks();

        $this->assertSame(1, $summary['lost']);
        $s = $this->setting($r);
        $this->assertSame('pending', $s->custom_domain_status);
        $this->assertNull($s->custom_domain_verified_at);
        $this->assertSame('shop.example.com', $s->custom_domain); // ادعا حفظ می‌شود تا مالک اصلاح کند
        $this->assertTrue(AuditLog::where('action', 'reseller.domain.lost')->exists());
        $this->assertFalse($this->domains()->isServable('shop.example.com'));
        $this->get('https://platform.test.melorin.io/health/domain-allowed?domain=shop.example.com')->assertNotFound();
        $this->get('http://shop.example.com/manage/domain')->assertNotFound();
    }

    #[Test]
    public function a_demoted_domain_recovers_when_the_txt_returns(): void
    {
        $r = $this->verifiedStore();
        $token = $this->setting($r)->custom_domain_token;
        unset($this->dnsRecords['_melorin-verify.shop.example.com']);
        $this->backdate($r, ['custom_domain_checked_at' => now()->subHours(80)]);
        $this->domains()->runChecks();

        $this->dnsRecords['_melorin-verify.shop.example.com'] = [$token];

        $this->assertSame(1, $this->domains()->runChecks()['verified']);
        $this->assertTrue($this->domains()->isServable('shop.example.com'));
    }

    #[Test]
    public function the_check_ignores_a_stale_result_when_the_domain_changed_meanwhile(): void
    {
        $r = $this->pendingStore('old.example.com');
        $old = $this->setting($r);
        $oldToken = $old->custom_domain_token;
        $this->domains()->set($r, $this->owner($r), 'new.example.com');

        $apply = new \ReflectionMethod($this->domains(), 'applyCheck');

        $this->assertSame('skipped', $apply->invoke($this->domains(), $old->id, 'old.example.com', $oldToken, true));
        $this->assertSame('pending', $this->setting($r)->custom_domain_status);
        $this->assertSame('new.example.com', $this->setting($r)->custom_domain);
    }

    #[Test]
    public function the_check_does_nothing_when_the_feature_is_off(): void
    {
        $this->pendingStore();
        config(['melorin.domains.enabled' => false]);

        $this->assertSame(0, $this->domains()->runChecks()['checked']);
    }

    #[Test]
    public function the_artisan_command_reports_a_summary(): void
    {
        $this->pendingStore();

        $this->artisan('melorin:domains:check')
            ->expectsOutputToContain('checked=1')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_host_lookup_is_cached_and_invalidated_on_every_state_change(): void
    {
        config(['melorin.domains.cache_ttl' => 60]);
        $r = $this->verifiedStore();
        $owner = $this->owner($r);

        $this->assertNotNull($this->domains()->resellerForHost('shop.example.com'));
        // نوشتن مستقیم در DB (بدون سرویس) تا TTL دیده نمی‌شود ⇒ ثابت‌بودن کش
        $this->backdate($r, ['custom_domain_status' => 'pending']);
        $this->assertNotNull($this->domains()->resellerForHost('shop.example.com'));

        // تغییر از طریق سرویس ⇒ فوراً باطل
        $this->backdate($r, ['custom_domain_status' => 'verified']);
        Cache::flush();
        $this->assertNotNull($this->domains()->resellerForHost('shop.example.com'));
        $this->domains()->remove($r, $owner);
        $this->assertNull($this->domains()->resellerForHost('shop.example.com'));
    }

    #[Test]
    public function a_negative_host_lookup_is_invalidated_when_the_domain_gets_verified(): void
    {
        config(['melorin.domains.cache_ttl' => 60]);
        $r = $this->pendingStore();

        $this->assertNull($this->domains()->resellerForHost('shop.example.com'));

        $this->dnsRecords['_melorin-verify.shop.example.com'] = [$this->setting($r)->custom_domain_token];
        $this->assertTrue($this->domains()->verify($r, $this->owner($r)));

        $this->assertNotNull($this->domains()->resellerForHost('shop.example.com'));
    }

    #[Test]
    public function deactivating_the_reseller_takes_effect_despite_the_cache(): void
    {
        config(['melorin.domains.cache_ttl' => 60]);
        $r = $this->verifiedStore();
        $this->get('http://shop.example.com/')->assertOk();

        $r->forceFill(['status' => 'inactive'])->save();

        $this->get('http://shop.example.com/')->assertNotFound();
    }

    #[Test]
    public function malformed_hosts_are_rejected_without_a_lookup(): void
    {
        $this->assertNull($this->domains()->resellerForHost('bad host!.com'));
        $this->assertNull($this->domains()->resellerForHost(str_repeat('a', 260).'.com'));
    }

    #[Test]
    public function an_admin_can_revoke_a_domain_with_a_distinct_audit(): void
    {
        $r = $this->verifiedStore();
        $admin = Admin::factory()->create();

        $this->assertTrue($this->domains()->revoke($r, $admin));

        $this->assertNull($this->setting($r)->custom_domain);
        $this->assertFalse($this->domains()->isServable('shop.example.com'));
        $log = AuditLog::where('action', 'reseller.domain.revoked')->first();
        $this->assertNotNull($log);
        $this->assertSame('admin', $log->actor_type);
        $this->assertSame($admin->id, (int) $log->actor_id);
        $this->assertFalse($this->domains()->revoke($r, $admin)); // بار دوم: چیزی نمانده
    }

    #[Test]
    public function the_admin_panel_lists_the_domain_and_the_revoke_action_works_through_livewire(): void
    {
        $r = $this->verifiedStore();
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        Livewire::test(\App\Filament\Resources\ResellerResource\Pages\ListResellers::class)
            ->assertSee('shop.example.com')
            ->assertTableActionVisible('revoke_domain', $r)
            ->callTableAction('revoke_domain', $r)
            ->assertHasNoTableActionErrors();

        $this->assertNull($this->setting($r)->custom_domain);
        $this->assertSame('admin', AuditLog::where('action', 'reseller.domain.revoked')->value('actor_type'));

        Livewire::test(\App\Filament\Resources\ResellerResource\Pages\ListResellers::class)
            ->assertTableActionHidden('revoke_domain', $r);
    }

    #[Test]
    public function the_management_page_shows_the_auto_check_note_and_expiry(): void
    {
        $r = $this->pendingStore();
        $owner = $this->owner($r);

        $this->actingAs($owner)
            ->get(route('website.store.manage.domain', $r->slug))
            ->assertOk()
            ->assertSee('خودکار بررسی می‌شود')
            ->assertSee('آزاد می‌شود');
    }
}
