<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\GuestCheckout;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.4 — فاز W3 بند ۳، ۴، ۵ (نسخه‌ی محدود).
 * مرجع: docs/PHASE-W3-PART2-GUEST-PURCHASE.md
 */
class GuestPurchaseFlowTest extends TestCase
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

    protected function startGuest(Product $product, string $phone = '09120000001', ?string $email = null): void
    {
        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'علی رضایی',
            'guest_phone' => $phone,
            'guest_email' => $email,
        ]);
    }

    #[Test]
    public function a_new_guest_is_logged_in_and_sent_straight_to_checkout(): void
    {
        $product = $this->makeProduct();
        $this->startGuest($product);
        $guest = GuestCheckout::query()->firstOrFail();

        $response = $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $response->assertRedirect(route('website.checkout.show', $product->id));

        $user = User::query()->where('phone', '09120000001')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertEquals('website_guest_checkout', $user->joined_from);

        $this->assertEquals('consumed', $guest->fresh()->status);

        // مسیر بعدی همان Checkout تست‌شده‌ی موجود است.
        $this->get(route('website.checkout.show', $product->id))->assertOk();
    }

    #[Test]
    public function a_guest_whose_phone_already_belongs_to_a_real_user_is_sent_to_login_not_auto_merged(): void
    {
        $product = $this->makeProduct();
        User::factory()->create(['phone' => '09120000002']);

        $this->startGuest($product, phone: '09120000002');
        $guest = GuestCheckout::query()->firstOrFail();

        $response = $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $response->assertRedirect(route('website.login'));
        $this->assertGuest();

        // هیچ User جدیدی برای این شماره ساخته نشده — فقط همان قبلی وجود دارد.
        $this->assertEquals(1, User::query()->where('phone', '09120000002')->count());
        $this->assertEquals('pending', $guest->fresh()->status);
    }

    #[Test]
    public function reusing_a_consumed_guest_token_is_rejected(): void
    {
        $product = $this->makeProduct();
        $this->startGuest($product);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)->post(route('website.guest-checkout.purchase'));

        \Illuminate\Support\Facades\Auth::logout();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'))
            ->assertNotFound();

        $this->assertEquals(1, User::query()->where('phone', '09120000001')->count());
    }

    #[Test]
    public function a_guest_originated_user_can_set_a_password_from_the_order_page(): void
    {
        $product = $this->makeProduct();
        $this->startGuest($product);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)->post(route('website.guest-checkout.purchase'));
        $user = User::query()->where('phone', '09120000001')->firstOrFail();

        $this->actingAs($user)
            ->get(route('website.identity.complete-profile.show'))
            ->assertOk();

        $this->actingAs($user)->post(route('website.identity.complete-profile.store'), [
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(route('website.home'));

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-strong-password', $user->fresh()->password));
    }
}
