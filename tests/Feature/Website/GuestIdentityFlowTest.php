<?php

namespace Tests\Feature\Website;

use App\Models\CustomerAccount;
use App\Models\GuestCheckout;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Guest\GuestCheckoutService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * B2.3 — Guest Checkout Identity Flow (Master §3: G3، G5، G6، G7، G9).
 *
 *   Guest → Pending → Login / Register / Google → ادامه‌ی همان خرید
 *
 * این فاز جریان پایه‌ی W3 را بهبود می‌دهد (نه بازنویسی): نشست تکراری، لغو، کاربر
 * واردشده، تطبیق Case-insensitive، Audit مصرف، و روشن‌تر شدن Pending/Login/Register.
 */
class GuestIdentityFlowTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function startGuest($product, string $email = 'newbie@example.test'): GuestCheckout
    {
        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => $email]);

        return GuestCheckout::query()->latest('id')->firstOrFail();
    }

    protected function verifiedUser(string $email = 'member@example.test'): User
    {
        return User::factory()->create([
            'telegram_id' => null,
            'email' => $email,
            'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);
    }

    protected function enableGoogle(): void
    {
        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => null,
        ]);
    }

    #[Test]
    public function a_logged_in_user_is_sent_to_checkout_instead_of_the_guest_form(): void
    {
        $product = $this->makeSellableProduct();

        $this->actingAs($this->verifiedUser())
            ->get(route('website.guest-checkout.show', $product->id))
            ->assertRedirect(route('website.checkout.show', $product->id));
    }

    #[Test]
    public function a_logged_in_user_never_creates_a_guest_session(): void
    {
        $product = $this->makeSellableProduct();

        $this->actingAs($this->verifiedUser())
            ->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'x@example.test'])
            ->assertRedirect(route('website.checkout.show', $product->id));

        $this->assertEquals(0, GuestCheckout::query()->count());
    }

    #[Test]
    public function a_logged_in_user_on_the_pending_page_continues_the_same_checkout(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $this->actingAs($this->verifiedUser())
            ->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertRedirect(route('website.checkout.show', $product->id));
    }

    #[Test]
    public function starting_a_new_session_replaces_the_previous_pending_one_of_the_same_browser(): void
    {
        $product = $this->makeSellableProduct();
        $first = $this->startGuest($product, 'first@example.test');

        $this->withCookie('guest_checkout_token', $first->token)
            ->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'second@example.test'])
            ->assertRedirect(route('website.guest-checkout.pending'));

        $this->assertEquals('expired', $first->fresh()->status);
        $this->assertEquals(1, GuestCheckout::query()->where('status', 'pending')->count());
        $this->assertDatabaseHas('guest_checkouts', ['guest_email' => 'second@example.test', 'status' => 'pending']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_checkout_discarded',
            'target_type' => $first->getMorphClass(),
            'target_id' => $first->id,
        ]);
    }

    #[Test]
    public function cancelling_discards_the_session_clears_the_cookie_and_creates_nothing(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);
        $users = User::query()->count();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.cancel'))
            ->assertRedirect(route('website.products.show', $product->id))
            ->assertSessionHas('status')
            ->assertCookieExpired('guest_checkout_token');

        $this->assertEquals('expired', $guest->fresh()->status);
        $this->assertEquals($users, User::query()->count());
        $this->assertEquals(0, CustomerAccount::query()->count());
        $this->assertDatabaseCount('orders', 0);

        // نشست لغوشده دیگر قابل‌استفاده نیست.
        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();
    }

    #[Test]
    public function cancelling_without_a_session_is_harmless(): void
    {
        $this->post(route('website.guest-checkout.cancel'))
            ->assertRedirect(route('website.home'));
    }

    #[Test]
    public function a_main_session_cannot_be_cancelled_from_a_reseller_store(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);
        $reseller = Reseller::factory()->create(['status' => 'active']);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.store.guest-checkout.cancel', $reseller->slug));

        $this->assertEquals('pending', $guest->fresh()->status);
    }

    #[Test]
    public function the_collision_check_ignores_email_case_for_legacy_rows(): void
    {
        $product = $this->makeSellableProduct();
        $existing = User::factory()->create(['telegram_id' => null, 'email' => 'Taken@Example.test']);

        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'taken@example.test'])
            ->assertRedirect(route('website.login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_collision_detected',
            'target_id' => $existing->id,
        ]);
    }

    #[Test]
    public function consuming_is_audited_once_and_links_the_guest_session_to_the_user(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);
        $user = $this->verifiedUser();
        $service = app(GuestCheckoutService::class);

        $this->assertTrue($service->consume($guest, $user));
        $this->assertFalse($service->consume($guest, $user)); // idempotent

        $this->assertEquals(1, DB::table('audit_logs')->where('action', 'identity.guest_checkout_consumed')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_checkout_consumed',
            'actor_type' => 'customer',
            'actor_id' => $user->id,
            'target_id' => $guest->id,
        ]);
    }

    #[Test]
    public function a_consumed_session_can_never_be_discarded_back(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);
        $service = app(GuestCheckoutService::class);

        $service->consume($guest);

        $this->assertFalse($service->discard($guest));
        $this->assertEquals('consumed', $guest->fresh()->status);
    }

    #[Test]
    public function the_pending_page_shows_price_remaining_time_cancel_and_google(): void
    {
        $this->enableGoogle();
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $guest = $this->startGuest($product);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertSee(Money::format(120000))
            ->assertSee('دقیقه‌ی دیگر')
            ->assertSee('لغو و شروع دوباره')
            ->assertSee(route('website.guest-checkout.cancel'), false)
            ->assertSee('ادامه با Google')
            ->assertDontSee('تکمیل پرداخت');
    }

    #[Test]
    public function login_and_register_remind_the_guest_that_the_same_purchase_continues(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        // withCookie() روی خودِ نمونه‌ی تست ماندگار می‌شود (defaultCookies)؛ پس همه‌ی درخواست‌های
        // «بدون کوکی» باید قبل از اولین withCookie انجام شوند، وگرنه تکرار دوم حلقه دیگر بدون کوکی نیست.
        foreach (['website.login', 'website.register'] as $name) {
            $this->get(route($name))->assertOk()->assertDontSee('از همین‌جا ادامه پیدا می‌کند');
        }

        foreach (['website.login', 'website.register'] as $name) {
            $this->withCookie('guest_checkout_token', $guest->token)
                ->get(route($name))
                ->assertOk()
                ->assertSee($product->name)
                ->assertSee('از همین‌جا ادامه پیدا می‌کند');
        }
    }

    #[Test]
    public function a_product_that_became_unavailable_invalidates_the_pending_session(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $product->update(['status' => 'inactive']);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();

        $this->assertEquals('expired', $guest->fresh()->status);
    }

    #[Test]
    public function minutes_left_is_never_negative(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $this->assertGreaterThanOrEqual(44, $guest->minutesLeft());
        $this->assertLessThanOrEqual(GuestCheckoutService::TTL_MINUTES, $guest->minutesLeft());

        $guest->expires_at = now()->subMinutes(5);
        $this->assertSame(0, $guest->minutesLeft());
    }
}
