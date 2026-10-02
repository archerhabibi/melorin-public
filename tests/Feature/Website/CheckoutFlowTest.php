<?php

namespace Tests\Feature\Website;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithWebsiteFixtures;
use Tests\TestCase;

/**
 * Checkout مستقیم بدون Cart، پرداخت کیف‌پول.
 *
 * این تست‌ها در سطح HTTP اجرا می‌شوند (نه فقط صدا زدن مستقیم Service)
 * تا مسیر واقعی کاربر — از صفحه‌ی محصول تا صفحه‌ی سفارش — پوشش داده
 * شود، دقیقاً همان چیزی که Verification Matrix به‌عنوان
 * حداقل لازم برای رسیدن از IMPLEMENTED به TESTED می‌خواهد.
 */
class CheckoutFlowTest extends TestCase
{
    use InteractsWithWebsiteFixtures, RefreshDatabase;

    protected IdentityService $identity;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
        $this->wallet = app(WalletService::class);

        $this->fakeSanaeiPanel();
    }


    #[Test]
    public function guest_is_redirected_to_login_instead_of_checkout(): void
    {
        $product = $this->makeSellableProduct();

        $this->get(route('website.checkout.show', $product->id))
            ->assertRedirect(route('website.login'));
    }

    #[Test]
    public function an_authenticated_customer_can_complete_a_wallet_checkout(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 200000);

        $this->actingAs($user)
            ->get(route('website.checkout.show', $product->id))
            ->assertOk()
            ->assertSee('idempotency_token', false);

        $response = $this->actingAs($user)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'test-token-1',
        ]);

        $order = Order::query()->where('customer_account_id', $customer->id)->firstOrFail();

        $response->assertRedirect(route('website.orders.show', $order->id));
        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);
        $this->assertEquals(80000, $this->wallet->getBalance($customer));

        $this->actingAs($user)
            ->get(route('website.orders.show', $order->id))
            ->assertOk()
            ->assertSee('تحویل‌شده');
    }

    #[Test]
    public function resubmitting_the_same_idempotency_token_does_not_charge_twice(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 200000);

        $this->actingAs($user)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'same-token',
        ]);
        $this->actingAs($user)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'same-token',
        ]);

        $this->assertEquals(1, Order::query()->where('customer_account_id', $customer->id)->count());
        $this->assertEquals(80000, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function checkout_is_blocked_when_wallet_balance_is_insufficient(): void
    {
        $product = $this->makeSellableProduct(mainPrice: 120000);
        $user = User::factory()->create();
        $this->identity->resolveCustomerAccount($user, StoreContext::main());

        $response = $this->actingAs($user)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'insufficient-1',
        ]);

        $response->assertSessionHasErrors('checkout');
        $this->assertEquals(0, Order::query()->count());
    }

    #[Test]
    public function a_customer_cannot_view_another_customers_order(): void
    {
        $product = $this->makeSellableProduct();
        $owner = User::factory()->create();
        $ownerCustomer = $this->identity->resolveCustomerAccount($owner, StoreContext::main());
        $this->wallet->credit($ownerCustomer, 200000);

        $this->actingAs($owner)->post(route('website.checkout.store', $product->id), [
            'idempotency_token' => 'owner-token',
        ]);
        $order = Order::query()->where('customer_account_id', $ownerCustomer->id)->firstOrFail();

        $stranger = User::factory()->create();
        $this->identity->resolveCustomerAccount($stranger, StoreContext::main());

        $this->actingAs($stranger)
            ->get(route('website.orders.show', $order->id))
            ->assertNotFound();
    }
}
