<?php

namespace Tests\Feature\Website;

use App\Models\GuestCheckout;
use App\Models\Order;
use App\Models\User;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * فاز W7 (نفر 5) - E2E صریح بند 64 زیرسند: Guest Post-Purchase E2E.
 * مرجع: docs/VERIFICATION-MATRIX.md
 *
 * مسیر کامل: مهمان -> خرید واقعی (سفارش ساخته می‌شود) -> «تکمیل حساب»
 * (رمز عبور تنظیم می‌کند، فاز W3 بند 5) -> خروج -> ورود دوباره با
 * ایمیل/رمز جدید (نه به‌عنوان مهمان) -> سفارش قبلی‌اش را می‌بیند. این
 * دقیقا همان «Identity Resolution بعد از خرید موفق» (بند 4 فاز W3)
 * است که باید شکست نخورد.
 */
class GuestPostPurchaseE2ETest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function a_guest_who_purchased_can_claim_the_account_and_return_to_see_their_order(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 80000);

        // 1) خرید مهمان تا رسیدن به Checkout (همان GuestE2ETest)
        $this->post(route('website.guest-checkout.store', $product->id), [
            'guest_name' => 'Mina Yousefi',
            'guest_phone' => '09121230000',
            'guest_email' => 'mina@example.test',
        ]);
        $guest = GuestCheckout::query()->firstOrFail();

        $this->withCookie('guest_checkout_token', $guest->token)
            ->post(route('website.guest-checkout.purchase'));

        $user = User::query()->where('phone', '09121230000')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);

        // 2) تکمیل خرید واقعی (شارژ کیف‌پول معادل تایید موفق درگاه -
        // زنجیره‌ی واقعی درگاه جای دیگر پوشش داده شده، همان توضیح
        // MainWebsiteE2ETest).
        $customerAccount = app(\App\Services\Core\Store\IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customerAccount, 100000);

        $this->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'e2e-guest-post-purchase',
        ]);

        $order = Order::query()->where('customer_account_id', $customerAccount->id)->firstOrFail();

        // 3) تکمیل حساب: رمز عبور تنظیم می‌کند (فاز W3 بند 5)
        $this->get(route('website.identity.complete-profile.show'))
            ->assertOk()
            ->assertSee('تکمیل حساب');

        $this->post(route('website.identity.complete-profile.store'), [
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        // 4) خروج واقعی از طریق همان Route (نه دستکاری مستقیم Guard) -
        // دقیقاً همان مسیری که یک کاربر واقعی طی می‌کند.
        $this->post(route('website.logout'));
        $this->assertGuest();

        // 5) ورود دوباره، این‌بار به‌عنوان یک کاربر واقعی با ایمیل/رمز
        // (نه دوباره از مسیر مهمان) - همان Identity Resolution که نباید
        // شکست بخورد.
        $login = $this->post(route('website.login'), [
            'email' => 'mina@example.test',
            'password' => 'a-brand-new-password',
        ]);
        $login->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());

        // 6) همان سفارشی که به‌عنوان مهمان خریده بود را می‌بیند.
        $this->get(route('website.orders.index'))
            ->assertOk()
            ->assertSee($product->name);
        $this->get(route('website.orders.show', $order->id))->assertOk();
    }
}
