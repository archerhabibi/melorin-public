<?php

namespace Tests\Feature\Website;

use App\Models\GuestCheckout;
use App\Models\Order;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * E2E مدل Guest (Master 2.7 §3.2):
 * Product → Guest Form (email) → Pending → Register → Verify Email →
 * ادامه‌ی همان خرید → CustomerAccount (Lazy) → Checkout → Order.
 * جایگزین GuestE2ETest و GuestPostPurchaseE2ETest قدیمی (X1/X2/X4 DEPRECATED).
 * زنجیره‌ی واقعی درگاه جای دیگر پوشش داده شده (شارژ مستقیم با WalletService).
 */
class GuestE2ETest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function a_guest_registers_verifies_and_completes_the_same_purchase(): void
    {
        Notification::fake();
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 90000);

        // ۱) بازدید ناشناس + فرم Guest (فقط email)
        $this->get(route('website.products.show', $product->id))->assertOk()->assertSee('مهمان');
        $this->get(route('website.guest-checkout.show', $product->id))->assertOk();
        $this->post(route('website.guest-checkout.store', $product->id), ['guest_email' => 'reza@example.test']);

        $guest = GuestCheckout::query()->firstOrFail();
        $this->assertGuest();

        // ۲) Pending
        $this->withCookie('guest_checkout_token', $guest->token)
            ->get(route('website.guest-checkout.pending'))->assertOk()->assertSee('reza@example.test');

        // ۳) Register (با همان Cookie) → صفحه‌ی تأیید Email
        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.register.store'), [
                'full_name' => 'Reza Karimi', 'email' => 'reza@example.test',
                'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
            ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'reza@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);

        // ۴) تا Verify: خرید مسدود و به صفحه‌ی تأیید هدایت می‌شود (CustomerAccount ساخته نمی‌شود)
        $this->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'g-e2e-blocked'])
            ->assertRedirect(route('verification.notice'));
        $this->assertNull(app(IdentityService::class)->findCustomerAccount($user, StoreContext::main()));

        // ۵) Verify با لینک امضاشده → بازگشت به همان Checkout
        $verifyUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );
        $this->withSession(['url.intended' => route('website.checkout.show', $product->id)])
            ->get($verifyUrl)
            ->assertRedirect(route('website.checkout.show', $product->id));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // ۶) خرید واقعی (Wallet Payment)
        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customer, 100000);

        $this->get(route('website.checkout.show', $product->id))->assertOk();
        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'g-e2e-ok'])
            ->assertRedirect();

        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();
        $this->get(route('website.orders.show', $order->id))->assertOk();
        $this->assertEquals('consumed', $guest->fresh()->status);
        $this->assertEquals(1, User::query()->where('email', 'reza@example.test')->count());
    }
}
