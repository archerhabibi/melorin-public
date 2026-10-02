<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\GuestCheckout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * Guest Checkout نماینده. مسیرهای Guest از نوع Shared هستند و زیر
 * /store/{slug} هم ثبت می‌شوند؛ این فایل «ادعا» را «تست‌شده» می‌کند:
 * قیمت، Scope توکن، پیام‌های Login، و خرید کامل با Debit دوگانه.
 * مرجع: docs/history/PHASE-W5-PART2-RESELLER-GUEST-CHECKOUT.md
 */
class ResellerGuestCheckoutTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function resellerProduct(int $customersPrice = 130000): array
    {
        $this->fakeSanaeiPanel();
        $category = Category::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($this->makeActiveSanaeiPanel()->id);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 150000,
            'reseller_price' => 100000,
            'status' => 'active',
        ]);

        $reseller = Reseller::factory()->create(['status' => 'active']);
        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        return [$product, $reseller];
    }

    protected function startGuest(Product $product, Reseller $reseller, string $email = 'guest-r@example.test'): GuestCheckout
    {
        $this->post(route('website.store.guest-checkout.store', [$reseller->slug, $product->id]), [
            'guest_email' => $email,
            'guest_name' => 'Guest Reseller',
        ]);

        return GuestCheckout::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function the_reseller_product_page_offers_guest_checkout_on_the_store_route(): void
    {
        [$product, $reseller] = $this->resellerProduct();

        $this->get(route('website.store.products.show', [$reseller->slug, $product->id]))
            ->assertOk()
            ->assertSee(route('website.store.guest-checkout.show', [$reseller->slug, $product->id]), false);
    }

    #[Test]
    public function the_guest_form_shows_customers_price_never_main_or_reseller_price(): void
    {
        [$product, $reseller] = $this->resellerProduct(customersPrice: 130000);

        $this->get(route('website.store.guest-checkout.show', [$reseller->slug, $product->id]))
            ->assertOk()
            ->assertSee(number_format(130000))
            ->assertDontSee(number_format(150000))
            ->assertDontSee(number_format(100000));
    }

    #[Test]
    public function the_guest_token_is_scoped_to_the_reseller_and_redirects_stay_inside_the_store(): void
    {
        [$product, $reseller] = $this->resellerProduct();

        $response = $this->post(route('website.store.guest-checkout.store', [$reseller->slug, $product->id]), [
            'guest_email' => 'scoped@example.test',
        ]);

        $response->assertRedirect(route('website.store.guest-checkout.pending', $reseller->slug));
        $response->assertCookie('guest_checkout_token');

        $guest = GuestCheckout::query()->firstOrFail();
        $this->assertEquals($reseller->id, $guest->reseller_id);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.store.guest-checkout.pending', $reseller->slug))
            ->assertOk();

        // همان توکن در فروشگاه اصلی معتبر نیست.
        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();
    }

    #[Test]
    public function a_token_from_another_reseller_store_is_rejected(): void
    {
        [$product, $reseller] = $this->resellerProduct();
        $other = Reseller::factory()->create(['status' => 'active']);

        $guest = $this->startGuest($product, $reseller);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.store.guest-checkout.pending', $other->slug))
            ->assertNotFound();
    }

    #[Test]
    public function a_product_the_reseller_has_not_enabled_cannot_start_guest_checkout(): void
    {
        [$product, $reseller] = $this->resellerProduct();
        $other = Reseller::factory()->create(['status' => 'active']); // این نماینده محصول را فعال نکرده

        $this->get(route('website.store.guest-checkout.show', [$other->slug, $product->id]))->assertNotFound();

        $this->post(route('website.store.guest-checkout.store', [$other->slug, $product->id]), [
            'guest_email' => 'x@example.test',
        ])->assertNotFound();

        $this->assertEquals(0, GuestCheckout::query()->count());
    }

    #[Test]
    public function a_colliding_email_is_sent_to_the_store_login_not_the_main_login(): void
    {
        [$product, $reseller] = $this->resellerProduct();
        User::factory()->create(['telegram_id' => null, 'email' => 'taken-r@example.test', 'password' => 'a-strong-password']);

        $response = $this->post(route('website.store.guest-checkout.store', [$reseller->slug, $product->id]), [
            'guest_email' => 'taken-r@example.test',
        ]);

        $response->assertRedirect(route('website.store.login', $reseller->slug));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.guest_collision_detected']);
    }

    #[Test]
    public function a_reseller_guest_logs_in_and_completes_a_full_purchase_with_both_wallets_and_a_scoped_order(): void
    {
        [$product, $reseller] = $this->resellerProduct(customersPrice: 130000);

        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'buyer-r@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);
        $guest = $this->startGuest($product, $reseller, 'other-r@example.test');

        // Login داخل همان فروشگاه، همان خرید Pending را ادامه می‌دهد.
        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.store.login.store', $reseller->slug), [
                'email' => 'buyer-r@example.test', 'password' => 'a-strong-password',
            ])
            ->assertRedirect(route('website.store.checkout.show', [$reseller->slug, $product->id]));

        $this->assertAuthenticatedAs($user);
        $this->assertEquals('pending', $guest->fresh()->status);

        // GET هیچ CustomerAccount نمی‌سازد (Lazy).
        $this->get(route('website.store.checkout.show', [$reseller->slug, $product->id]))->assertOk();
        $this->assertNull(app(IdentityService::class)->findCustomerAccount($user, StoreContext::reseller($reseller)));

        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::reseller($reseller));
        $this->assertEquals($reseller->id, $customer->reseller_id);

        app(WalletService::class)->credit($customer, 200000);
        app(WalletService::class)->credit($reseller, 500000);

        $response = $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.store.checkout.store', [$reseller->slug, $product->id]), [
                'idempotency_token' => 'reseller-guest-1',
            ]);

        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();
        $response->assertRedirect(route('website.store.orders.show', [$reseller->slug, $order->id]));

        $this->assertEquals($reseller->id, $order->reseller_id);
        $this->assertEquals(130000, (int) $order->customers_price);
        $this->assertEquals(70000, app(WalletService::class)->getBalance($customer));
        $this->assertEquals('consumed', $guest->fresh()->status);
    }
}
