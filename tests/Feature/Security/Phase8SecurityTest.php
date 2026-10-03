<?php

namespace Tests\Feature\Security;

use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز ۸ — اصلاحات ممیزی امنیتی (S-01 … S-10).
 * گزارش: docs/history/PHASE-8-SECURITY-AUDIT.md
 */
class Phase8SecurityTest extends TestCase
{
    use FakesTelegram, InteractsWithWebsiteFixtures, RefreshDatabase;

    // ---------- S-01 / S-02: Webhook Fail-closed ----------

    #[Test]
    public function a_reseller_webhook_without_any_stored_secret_is_rejected_even_with_a_header(): void
    {
        $reseller = Reseller::factory()->create(['webhook_slug' => 'no-secret-shop']);
        $reseller->forceFill(['webhook_secret' => null])->save();

        $payload = ['update_id' => 1];

        $this->postJson('/reseller-bot/webhook/no-secret-shop', $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', '')
            ->postJson('/reseller-bot/webhook/no-secret-shop', $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'anything')
            ->postJson('/reseller-bot/webhook/no-secret-shop', $payload)->assertForbidden();
    }

    #[Test]
    public function the_main_webhook_is_rejected_when_no_secret_is_configured(): void
    {
        config(['telegram.bots.main.token' => '123:ABC', 'telegram.webhook_secret' => null]);

        $this->postJson('/telegram/webhook/123:ABC', ['update_id' => 1])->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', '')
            ->postJson('/telegram/webhook/123:ABC', ['update_id' => 2])->assertForbidden();
    }

    #[Test]
    public function the_main_webhook_requires_the_exact_secret(): void
    {
        config(['telegram.bots.main.token' => '123:ABC', 'telegram.webhook_secret' => 'correct-secret']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
            ->postJson('/telegram/webhook/123:ABC', ['update_id' => 3])->assertForbidden();

        $this->postJson('/telegram/webhook/123:ABC', ['update_id' => 4])->assertForbidden();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'correct-secret')
            ->postJson('/telegram/webhook/123:ABC', ['update_id' => 5])->assertOk();

        // توکن اشتباه در URL همچنان 404 است.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'correct-secret')
            ->postJson('/telegram/webhook/999:ZZZ', ['update_id' => 6])->assertNotFound();
    }

    // ---------- S-03: Zarinpal callback bound to Authority ----------

    protected function pendingGatewayPayment(): array
    {
        $this->fakeTelegram();
        Http::fake([
            '*/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'AUTH-SECRET']], 200),
            '*/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 1]], 200),
        ]);

        $method = $this->makeZarinpalMethod();
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'victim@example.test', 'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id,
        ])->assertRedirect();

        auth()->logout();

        return [Payment::query()->where('user_id', $user->id)->firstOrFail(), $user];
    }

    #[Test]
    public function an_anonymous_cancel_callback_without_the_authority_cannot_reject_someone_elses_payment(): void
    {
        [$payment] = $this->pendingGatewayPayment();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Status=NOK')->assertNotFound();
        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=WRONG&Status=NOK')->assertNotFound();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    #[Test]
    public function a_forged_ok_callback_with_a_wrong_authority_never_confirms_or_credits(): void
    {
        [$payment, $user] = $this->pendingGatewayPayment();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=WRONG&Status=OK')->assertNotFound();

        $this->assertSame('pending', $payment->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'verify.json'));
    }

    #[Test]
    public function the_victim_can_still_pay_after_an_attacker_probe(): void
    {
        [$payment] = $this->pendingGatewayPayment();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Status=NOK')->assertNotFound();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH-SECRET&Status=OK')->assertOk();

        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    #[Test]
    public function a_genuine_cancel_with_the_correct_authority_still_rejects(): void
    {
        [$payment] = $this->pendingGatewayPayment();

        $this->get('/payment/zarinpal/callback?payment_id='.$payment->id.'&Authority=AUTH-SECRET&Status=NOK')->assertOk();

        $this->assertSame('rejected', $payment->fresh()->status);
    }

    // ---------- S-04: Trusted proxies ----------

    #[Test]
    public function the_client_ip_is_read_from_a_trusted_loopback_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/');

        $this->assertSame('203.0.113.9', request()->ip());
        $this->assertTrue(request()->isSecure());
    }

    #[Test]
    public function forwarded_headers_from_an_untrusted_peer_are_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get('http://localhost/');

        $this->assertSame('198.51.100.7', request()->ip());
        $this->assertFalse(request()->isSecure());
    }

    // ---------- S-08: Security headers ----------

    #[Test]
    public function website_responses_carry_the_baseline_security_headers(): void
    {
        // آدرس صریح http:// تا نتیجه به APP_URL محیط (https در Production) وابسته نباشد.
        $response = $this->get('http://localhost/')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $response->assertHeaderMissing('Strict-Transport-Security'); // HTTP ساده
    }

    #[Test]
    public function hsts_is_sent_only_over_https(): void
    {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function the_admin_login_page_also_gets_the_headers(): void
    {
        $this->get('http://localhost/admin/login')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    // ---------- S-05: Register throttle ----------

    #[Test]
    public function registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('website.register.store'), [
                'full_name' => 'x', 'email' => "bad{$i}", 'password' => 'a', 'password_confirmation' => 'b',
            ])->assertSessionHasErrors();
            auth()->logout();
        }

        $this->post(route('website.register.store'), [
            'full_name' => 'x', 'email' => 'late@example.test', 'password' => 'a', 'password_confirmation' => 'b',
        ])->assertStatus(429);
    }

    // ---------- S-07: Renewal idempotency ----------

    protected function renewableAccount(User $user): Account
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create();
        $category->serverPanels()->attach($panel);
        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => 100000]);
        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->charge($customer, 500000);

        return Account::factory()->create([
            'user_id' => $user->id, 'customer_account_id' => $customer->id, 'product_id' => $product->id,
            'server_panel_id' => $panel->id, 'panel_username' => 'melorin_existing',
            'status' => 'active', 'expires_at' => now()->addDays(5),
        ]);
    }

    #[Test]
    public function the_same_renew_token_submitted_twice_charges_once(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user/*' => Http::response(['username' => 'melorin_existing'], 200),
        ]);

        $user = User::factory()->create();
        $account = $this->renewableAccount($user);
        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-1']);
        $afterFirst = app(WalletService::class)->balance($customer);

        $this->actingAs($user)->post(route('website.accounts.renew', $account->id), ['idempotency_token' => 'tok-1']);

        $this->assertSame(400000, $afterFirst);
        $this->assertSame(400000, app(WalletService::class)->balance($customer));
    }

    #[Test]
    public function the_renew_form_embeds_a_fresh_idempotency_token(): void
    {
        $user = User::factory()->create();
        $account = $this->renewableAccount($user);

        $this->actingAs($user)->get(route('website.accounts.show', $account->id))
            ->assertOk()
            ->assertSee('name="idempotency_token"', false);
    }

    // ---------- S-09: Receipt re-upload cleanup ----------

    #[Test]
    public function re_uploading_a_receipt_deletes_the_previous_file(): void
    {
        Storage::fake('local');
        $this->fakeTelegram();

        $method = $this->makeCardToCardMethod();
        $user = User::factory()->create(['telegram_id' => null, 'email_verified_at' => now()]);

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 100000, 'payment_method_id' => $method->id,
        ]);
        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'receipt' => UploadedFile::fake()->image('a.png'), 'depositor_name' => 'Ali',
        ])->assertSessionDoesntHaveErrors();
        $first = substr($payment->fresh()->receipt_image, strlen('website:'));

        $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'receipt' => UploadedFile::fake()->image('b.png'), 'depositor_name' => 'Ali',
        ])->assertSessionDoesntHaveErrors();
        $second = substr($payment->fresh()->receipt_image, strlen('website:'));

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }
}
