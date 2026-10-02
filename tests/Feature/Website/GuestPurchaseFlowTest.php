<?php

namespace Tests\Feature\Website;

use App\Models\CustomerAccount;
use App\Models\GuestCheckout;
use App\Models\Order;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * Master 2.7 §3: G3–G7 (Pending → Login/Register → ادامه‌ی همان خرید،
 * بدون User/CustomerAccount/Purchase تکراری، بدون Merge/Login خودکار).
 * جایگزین نسخه‌ی قدیمی این فایل (که ساخت User از Guest را تأیید می‌کرد).
 */
class GuestPurchaseFlowTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function startGuest($product, string $email = 'newbie@example.test', array $extra = []): GuestCheckout
    {
        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => $email] + $extra);

        return GuestCheckout::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function the_pending_page_offers_login_and_register_not_a_purchase_button(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertSee(route('website.login'), false)
            ->assertSee(route('website.register'), false)
            ->assertDontSee('تکمیل پرداخت');
    }

    #[Test]
    public function the_pending_page_hides_name_and_phone_when_not_provided(): void
    {
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertOk()
            ->assertDontSee('شماره تماس')
            ->assertDontSee('>نام<', false);
    }

    #[Test]
    public function login_after_pending_continues_the_same_purchase(): void
    {
        $product = $this->makeSellableProduct();
        User::factory()->create([
            'telegram_id' => null, 'email' => 'member@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);

        // ایمیل Guest با ایمیل حساب فرق دارد ⇒ تصادم نیست؛ کاربر با حساب خودش وارد می‌شود.
        $guest = $this->startGuest($product, 'other-guest@example.test');

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.login.store'), ['email' => 'member@example.test', 'password' => 'a-strong-password'])
            ->assertRedirect(route('website.checkout.show', $product->id));

        $this->assertEquals('pending', $guest->fresh()->status); // تا خرید واقعی مصرف نمی‌شود
    }

    #[Test]
    public function register_after_pending_prefills_the_form_and_returns_to_the_same_checkout_after_verification(): void
    {
        Notification::fake();
        $product = $this->makeSellableProduct();
        $guest = $this->startGuest($product, 'fresh@example.test', ['guest_name' => 'Mina', 'guest_phone' => '09121230000']);

        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.register'))
            ->assertOk()
            ->assertSee('fresh@example.test')
            ->assertSee('Mina')
            ->assertSee('09121230000');

        $usersBefore = User::query()->count();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.register.store'), [
                'full_name' => 'Mina', 'email' => 'fresh@example.test', 'phone' => '09121230000',
                'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
            ])
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', route('website.checkout.show', $product->id));

        // دقیقاً یک User (از Register)، نه از Guest؛ هیچ CustomerAccount/Order ای هنوز نیست.
        $this->assertEquals($usersBefore + 1, User::query()->count());
        $this->assertEquals(0, CustomerAccount::query()->count());
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals('pending', $guest->fresh()->status);
    }

    #[Test]
    public function a_guest_email_matching_an_existing_user_is_sent_to_login_with_audit_and_no_merge(): void
    {
        $product = $this->makeSellableProduct();
        $existing = User::factory()->create(['telegram_id' => null, 'email' => 'taken@example.test', 'password' => 'a-strong-password']);
        $usersBefore = User::query()->count();

        $response = $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'taken@example.test']);

        $response->assertRedirect(route('website.login'));
        $response->assertCookie('guest_checkout_token');
        $this->assertGuest();
        $this->assertEquals($usersBefore, User::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'identity.guest_collision_detected',
            'target_type' => $existing->getMorphClass(),
            'target_id' => $existing->id,
        ]);
    }

    #[Test]
    public function a_colliding_phone_is_also_detected_when_provided(): void
    {
        $product = $this->makeSellableProduct();
        User::factory()->create(['telegram_id' => null, 'phone' => '09125550009']);

        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_email' => 'someone-else@example.test', 'guest_phone' => '09125550009',
        ])->assertRedirect(route('website.login'));

        $this->assertGuest();
    }

    #[Test]
    public function after_a_collision_login_continues_the_same_purchase(): void
    {
        $product = $this->makeSellableProduct();
        User::factory()->create([
            'telegram_id' => null, 'email' => 'taken@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);

        $response = $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'taken@example.test']);
        $token = GuestCheckout::query()->firstOrFail()->token;

        $this->withCookie('guest_checkout_token', $token)
            ->post(route('website.login.store'), ['email' => 'taken@example.test', 'password' => 'a-strong-password'])
            ->assertRedirect(route('website.checkout.show', $product->id));
    }

    #[Test]
    public function completing_the_purchase_consumes_the_guest_session_and_creates_no_duplicates(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 80000);
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'buyer@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);
        $guest = $this->startGuest($product, 'other@example.test');

        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customer, 200000);

        $this->actingAs($user)->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'guest-consume-1'])
            ->assertRedirect();

        $this->assertEquals('consumed', $guest->fresh()->status);
        $this->assertEquals(1, Order::query()->where('customer_account_id', $customer->id)->count());
        $this->assertEquals(1, CustomerAccount::query()->where('user_id', $user->id)->count());

        // توکن مصرف‌شده دیگر قابل‌استفاده نیست.
        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))
            ->assertNotFound();
    }
}
