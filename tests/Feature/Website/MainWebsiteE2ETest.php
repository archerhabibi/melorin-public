<?php

namespace Tests\Feature\Website;

use App\Models\Order;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * Main Website E2E.
 * مرجع: docs/history/VERIFICATION-MATRIX.md
 *
 * مسیر کامل: ثبت‌نام -> صفحه‌ی محصول -> Checkout -> (موجودی ناکافی،
 * پس با WalletService مستقیم شارژ می‌شود - معادل همان چیزی که
 * درگاه‌های واقعی بعد از تایید Webhook انجام می‌دهند، بدون نیاز به
 * Mock کردن کل زنجیره‌ی Zarinpal/ادمین که خودشان جای دیگر تست شده‌اند)
 * -> پرداخت از کیف‌پول -> سفارش ساخته و قابل مشاهده است.
 */
class MainWebsiteE2ETest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    #[Test]
    public function a_new_customer_can_register_and_complete_a_full_purchase_on_main_website(): void
    {
        $this->fakeSanaeiPanel();
        $product = $this->makeSellableProduct(mainPrice: 100000);

        $this->post(route('website.register.store'), [
            'full_name' => 'Sara Ahmadi',
            'email' => 'sara@example.test',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]);
        $user = User::query()->where('email', 'sara@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        // Master G11: تا Verify نشدن Email خرید مسدود است؛ بقیه‌ی سایت آزاد.
        $this->get(route('website.products.show', $product->id))->assertOk();
        $this->get(route('website.wallet.show'))->assertOk();
        $this->post(route('website.checkout.store', $product->id), ['idempotency_token' => 'e2e-main-blocked'])
            ->assertRedirect(route('verification.notice'));

        $this->get(\Illuminate\Support\Facades\URL::temporarySignedRoute(
            'verification.verify', now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        ));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $customer = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
        app(WalletService::class)->credit($customer, 200000);

        $this->get(route('website.checkout.show', $product->id))->assertOk();
        $response = $this->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'e2e-main-token',
        ]);

        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();
        $response->assertRedirect(route('website.orders.show', $order->id));

        $this->get(route('website.orders.show', $order->id))->assertOk();
        $this->get(route('website.orders.index'))->assertOk()->assertSee($product->name);

        $this->get(route('website.wallet.show'))->assertOk();
        $this->assertEquals(100000, app(WalletService::class)->getBalance($customer));
    }
}
