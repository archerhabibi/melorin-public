<?php

namespace Tests\Feature\Website;

use App\Models\User;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Store\EmailNotVerifiedException;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز ۴ — Email Verification + Gate (Master 2.7 G11، D-7، D-8؛ شکاف C10).
 * فقط Purchase و Wallet Charge مسدود می‌شوند؛ Enforcement در Core است.
 */
class EmailVerificationTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected function unverifiedUser(): User
    {
        return User::factory()->create([
            'telegram_id' => null, 'email' => 'unverified@example.test',
            'password' => 'a-strong-password', 'email_verified_at' => null,
        ]);
    }

    protected function signedVerifyUrl(User $user, int $minutes = 60, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id, 'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    #[Test]
    public function registering_sends_a_verification_email_and_lands_on_the_notice_page(): void
    {
        Notification::fake();

        $this->post(route('website.register.store'), [
            'full_name' => 'New User', 'email' => 'new@example.test',
            'password' => 'a-strong-password', 'password_confirmation' => 'a-strong-password',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->actingAs($user)->get(route('verification.notice'))->assertOk()->assertSee('new@example.test');
    }

    #[Test]
    public function a_valid_signed_link_verifies_the_email_and_is_idempotent(): void
    {
        $user = $this->unverifiedUser();

        $this->actingAs($user)->get($this->signedVerifyUrl($user))->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $first = $user->fresh()->email_verified_at;
        $this->actingAs($user)->get($this->signedVerifyUrl($user))->assertRedirect();
        $this->assertEquals($first, $user->fresh()->email_verified_at);
    }

    #[Test]
    public function tampered_expired_or_unsigned_links_are_rejected(): void
    {
        $user = $this->unverifiedUser();

        // hash اشتباه
        $this->actingAs($user)->get($this->signedVerifyUrl($user, 60, sha1('other@example.test')))->assertForbidden();
        // منقضی
        $this->actingAs($user)->get($this->signedVerifyUrl($user, -5))->assertForbidden();
        // بدون امضا
        $this->actingAs($user)->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function another_users_link_cannot_verify_this_account(): void
    {
        $victim = $this->unverifiedUser();
        $attacker = User::factory()->create([
            'telegram_id' => null, 'email' => 'attacker@example.test', 'password' => 'a-strong-password',
        ]);

        // لینک امضاشده‌ی قربانی، با نشست مهاجم
        $this->actingAs($attacker)->get($this->signedVerifyUrl($victim))->assertForbidden();

        $this->assertFalse($victim->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function an_unverified_user_can_resend_the_link(): void
    {
        Notification::fake();
        $user = $this->unverifiedUser();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function the_rest_of_the_site_stays_open_for_an_unverified_user(): void
    {
        $product = $this->makeSellableProduct();
        $user = $this->unverifiedUser();

        $this->actingAs($user);
        $this->get(route('website.home'))->assertOk();
        $this->get(route('website.products.show', $product->id))->assertOk();
        $this->get(route('website.wallet.show'))->assertOk();
        $this->get(route('website.orders.index'))->assertOk();
        $this->get(route('website.accounts.index'))->assertOk();
        $this->get(route('website.checkout.show', $product->id))->assertOk(); // مرور آزاد؛ فقط اکشن خرید مسدود است
        $this->get(route('website.wallet.charge.show'))->assertOk();
    }

    #[Test]
    public function an_unverified_user_cannot_purchase_and_no_customer_account_or_order_is_created(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 50000);
        $user = $this->unverifiedUser();

        $this->actingAs($user)
            ->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'gate-1'])
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('url.intended', route('website.checkout.show', $product->id));

        $this->assertNull(app(IdentityService::class)->findCustomerAccount($user, StoreContext::main()));
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_core_purchase_service_itself_enforces_the_gate(): void
    {
        // Enforcement در Core است، نه Middleware/Controller (Master G11).
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 50000);
        $user = $this->unverifiedUser();
        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customer, 100000);

        $this->expectException(EmailNotVerifiedException::class);

        try {
            app(\App\Services\Core\Purchase\PurchaseService::class)->purchase(
                customer: $customer, product: $product, store: StoreContext::main(),
                salesChannel: 'website', idempotencyKey: 'core-gate-1',
            );
        } finally {
            $this->assertDatabaseCount('orders', 0);
            $this->assertEquals(100000, app(WalletService::class)->getBalance($customer));
        }
    }

    #[Test]
    public function the_gate_exception_is_a_purchase_not_allowed_exception_so_other_channels_show_its_message(): void
    {
        $this->assertInstanceOf(PurchaseNotAllowedException::class, new EmailNotVerifiedException);
    }

    #[Test]
    public function an_unverified_user_cannot_charge_the_wallet(): void
    {
        $method = $this->makeCardToCardMethod();
        $user = $this->unverifiedUser();

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id,
        ])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('payments', 0);
    }

    #[Test]
    public function a_verified_user_can_charge_and_purchase(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 50000);
        $method = $this->makeCardToCardMethod();
        $user = User::factory()->create([
            'telegram_id' => null, 'email' => 'ok@example.test', 'password' => 'a-strong-password',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000, 'payment_method_id' => $method->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('payments', 1);

        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customer, 100000);

        $this->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'gate-ok'])
            ->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function users_without_any_email_such_as_telegram_bot_users_are_not_gated(): void
    {
        // تفسیر Implementation: Gate فقط برای «Email دارد ولی Verify نکرده».
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 50000);
        $botUser = User::factory()->create(); // بدون email (مثل Userهای ساخته‌شده توسط ربات)
        $customer = app(IdentityService::class)->resolveCustomerAccount($botUser, StoreContext::main());
        app(WalletService::class)->credit($customer, 100000);

        $account = app(\App\Services\Core\Purchase\PurchaseService::class)->purchase(
            customer: $customer, product: $product, store: StoreContext::main(),
            salesChannel: 'main_bot', idempotencyKey: 'bot-no-email-1',
        );

        $this->assertNotNull($account->id);
    }
}
