<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.3 — فاز W3 بند ۱: Guest Checkout Token.
 * مرجع: docs/PHASE-W3-PART1-GUEST-CHECKOUT-TOKEN.md
 */
class GuestCheckoutTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProduct(): Product
    {
        $category = Category::factory()->create(['status' => 'active']);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 120000,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function a_guest_can_start_checkout_without_authentication(): void
    {
        $product = $this->makeProduct();

        $response = $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'علی رضایی',
            'guest_phone' => '09120000000',
        ]);

        $response->assertRedirect(route('website.guest-checkout.pending'));
        $response->assertCookie('guest_checkout_token');

        $this->assertDatabaseHas('guest_checkouts', [
            'product_id' => $product->id,
            'guest_name' => 'علی رضایی',
            'guest_phone' => '09120000000',
            'status' => 'pending',
        ]);

        $guest = GuestCheckout::query()->first();
        $this->assertNotNull($guest->expires_at);
        $this->assertTrue($guest->expires_at->isFuture());
    }

    #[Test]
    public function guest_checkout_requires_name_and_phone_but_not_email(): void
    {
        $product = $this->makeProduct();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_phone' => '09120000000',
        ])->assertSessionHasErrors('guest_name');

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'علی رضایی',
        ])->assertSessionHasErrors('guest_phone');

        $this->assertEquals(0, GuestCheckout::query()->count());
    }

    #[Test]
    public function the_pending_page_reads_the_token_from_the_cookie(): void
    {
        $product = $this->makeProduct();

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'علی رضایی',
            'guest_phone' => '09120000000',
        ]);
        $guest = GuestCheckout::query()->firstOrFail();

        $response = $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'));

        $response->assertOk();
        $response->assertSee('علی رضایی');
    }

    #[Test]
    public function an_expired_or_unknown_token_is_rejected(): void
    {
        $this->withCookie('guest_checkout_token', 'not-a-real-token')
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();

        $product = $this->makeProduct();
        $guest = GuestCheckout::create([
            'token' => 'expired-token',
            'product_id' => $product->id,
            'guest_name' => 'علی',
            'guest_phone' => '0912',
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
        $product = $this->makeProduct();
        $reseller = Reseller::factory()->create();

        $guest = GuestCheckout::create([
            'token' => 'reseller-token',
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'guest_name' => 'علی',
            'guest_phone' => '0912',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();
    }
}
