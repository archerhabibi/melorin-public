<?php

namespace Tests\Feature\Website;

use App\Models\CustomerAccount;
use App\Models\GuestCheckout;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * Guest Checkout (Master 2.7 §3: G1–G5، G8) — مدل جدید.
 * (نسخه‌ی قدیمی: name/phone الزامی، email اختیاری — DEPRECATED.)
 */
class GuestCheckoutTokenTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function a_guest_can_start_checkout_with_only_an_email(): void
    {
        $product = $this->makeSellableProduct();

        $response = $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_email' => 'Guest@Example.test',
        ]);

        $response->assertRedirect(route('website.guest-checkout.pending'));
        $response->assertCookie('guest_checkout_token');

        $guest = GuestCheckout::query()->firstOrFail();
        $this->assertEquals('guest@example.test', $guest->guest_email); // normalize
        $this->assertNull($guest->guest_name);
        $this->assertNull($guest->guest_phone);
        $this->assertEquals('pending', $guest->status);
        $this->assertTrue($guest->expires_at->isFuture());
    }

    #[Test]
    public function name_and_phone_are_optional_but_stored_when_given(): void
    {
        $product = $this->makeSellableProduct();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_email' => 'a@example.test',
            'guest_name' => 'علی رضایی',
            'guest_phone' => '09120000000',
        ])->assertRedirect(route('website.guest-checkout.pending'));

        $this->assertDatabaseHas('guest_checkouts', [
            'guest_email' => 'a@example.test',
            'guest_name' => 'علی رضایی',
            'guest_phone' => '09120000000',
        ]);
    }

    #[Test]
    public function email_is_required_and_must_be_valid(): void
    {
        $product = $this->makeSellableProduct();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'علی', 'guest_phone' => '0912',
        ])->assertSessionHasErrors('guest_email');

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_email' => 'not-an-email',
        ])->assertSessionHasErrors('guest_email');

        $this->assertEquals(0, GuestCheckout::query()->count());
    }

    #[Test]
    public function starting_a_guest_checkout_creates_no_user_customer_account_or_order(): void
    {
        $product = $this->makeSellableProduct();
        $usersBefore = User::query()->count();

        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'x@example.test']);

        $this->assertEquals($usersBefore, User::query()->count());
        $this->assertEquals(0, CustomerAccount::query()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertGuest();
    }

    #[Test]
    public function the_pending_page_reads_the_token_from_the_cookie_and_shows_the_email(): void
    {
        $product = $this->makeSellableProduct();

        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'seen@example.test']);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertSee('seen@example.test');
    }

    #[Test]
    public function an_expired_or_unknown_token_is_rejected(): void
    {
        $this->withCookie('guest_checkout_token', 'not-a-real-token')
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();

        $product = $this->makeSellableProduct();
        $guest = GuestCheckout::create([
            'token' => 'expired-token',
            'product_id' => $product->id,
            'guest_email' => 'old@example.test',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();

        $this->assertEquals('expired', $guest->fresh()->status);
    }

    #[Test]
    public function a_guest_token_created_in_a_reseller_store_is_not_valid_in_the_main_store(): void
    {
        $product = $this->makeSellableProduct();
        $reseller = Reseller::factory()->create();

        $guest = GuestCheckout::create([
            'token' => 'reseller-token',
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'guest_email' => 'r@example.test',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();
    }

    #[Test]
    public function the_old_guest_purchase_route_is_gone(): void
    {
        // X4 (DEPRECATED): ساخت خودکار User از Guest + Auth::login.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('website.guest-checkout.purchase'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('website.identity.complete-profile.show'));
    }
}
