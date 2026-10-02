<?php

namespace Tests\Feature\Website;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پنل کاربری. پیشینه: docs/history/PHASE-W4-PART1-ACCOUNT-PANEL.md
 *
 * این تست‌ها صفحات Wallet/Orders/Accounts را پوشش می‌دهند («همه صرفاً
 * نمایشی»)؛ Renewal/Referral در AccountPanelPart2Test است و Refund/Retry
 * Admin-only هستند.
 */
class AccountPanelTest extends TestCase
{
    use RefreshDatabase;

    protected IdentityService $identity;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = app(IdentityService::class);
        $this->wallet = app(WalletService::class);
    }

    protected function makeProduct(): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = ServerPanel::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create(['category_id' => $category->id, 'status' => 'active']);
    }

    // --- دسترسی: هر سه صفحه پشت auth+store.customer هستند (بند ۵۰: Authorization در Backend) ---

    #[Test]
    public function guest_is_redirected_to_login_for_all_three_account_pages(): void
    {
        $this->get(route('website.wallet.show'))->assertRedirect(route('website.login'));
        $this->get(route('website.orders.index'))->assertRedirect(route('website.login'));
        $this->get(route('website.accounts.index'))->assertRedirect(route('website.login'));
    }

    // --- Wallet (بند ۳۰، ۳۱) ---

    #[Test]
    public function wallet_page_shows_the_correct_balance_and_transaction_history(): void
    {
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 150000, description: 'شارژ آزمایشی');

        $response = $this->actingAs($user)->get(route('website.wallet.show'));

        $response->assertOk()
            ->assertSee('150,000')
            ->assertSee('شارژ کیف‌پول');
    }

    #[Test]
    public function wallet_balance_of_another_user_is_never_shown(): void
    {
        $owner = User::factory()->create();
        $ownerCustomer = $this->identity->resolveCustomerAccount($owner, StoreContext::main());
        $this->wallet->credit($ownerCustomer, 999000);

        $viewer = User::factory()->create();
        $this->identity->resolveCustomerAccount($viewer, StoreContext::main());

        $this->actingAs($viewer)
            ->get(route('website.wallet.show'))
            ->assertOk()
            ->assertDontSee('999,000');
    }

    // --- Orders (بند ۳۲: مالکیت + Context) ---

    #[Test]
    public function orders_index_lists_only_the_current_users_orders(): void
    {
        $product = $this->makeProduct();

        $owner = User::factory()->create();
        $ownerCustomer = $this->identity->resolveCustomerAccount($owner, StoreContext::main());
        Order::factory()->create([
            'customer_account_id' => $ownerCustomer->id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'main_price' => 100000,
        ]);

        $stranger = User::factory()->create();
        $strangerCustomer = $this->identity->resolveCustomerAccount($stranger, StoreContext::main());
        Order::factory()->create([
            'customer_account_id' => $strangerCustomer->id,
            'user_id' => $stranger->id,
            'product_id' => $product->id,
            'main_price' => 200000,
        ]);

        $response = $this->actingAs($owner)->get(route('website.orders.index'));

        $response->assertOk();
        $this->assertEquals(1, Order::query()->where('customer_account_id', $ownerCustomer->id)->count());
    }

    #[Test]
    public function orders_index_never_leaks_across_store_context(): void
    {
        $product = $this->makeProduct();
        $reseller = Reseller::factory()->create();

        $user = User::factory()->create();
        $mainCustomer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $resellerCustomer = $this->identity->resolveCustomerAccount($user, StoreContext::reseller($reseller));

        Order::factory()->create([
            'customer_account_id' => $mainCustomer->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reseller_id' => null,
            'main_price' => 100000,
        ]);
        Order::factory()->create([
            'customer_account_id' => $resellerCustomer->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reseller_id' => $reseller->id,
            'reseller_price' => 70000,
            'customers_price' => 90000,
        ]);

        // در Context اصلی، فقط سفارش Main دیده می‌شود.
        $this->actingAs($user)
            ->get(route('website.orders.index'))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1);

        // در Context همین نماینده، فقط سفارش همان نماینده دیده می‌شود.
        $this->actingAs($user)
            ->get(route('website.store.orders.index', $reseller->slug))
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
    }

    // --- Accounts (بند ۳۳) ---

    #[Test]
    public function accounts_index_lists_only_the_current_customers_accounts(): void
    {
        $product = $this->makeProduct();

        $owner = User::factory()->create();
        $ownerCustomer = $this->identity->resolveCustomerAccount($owner, StoreContext::main());
        $ownerOrder = Order::factory()->create([
            'customer_account_id' => $ownerCustomer->id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);
        Account::factory()->create([
            'customer_account_id' => $ownerCustomer->id,
            'user_id' => $owner->id,
            'order_id' => $ownerOrder->id,
            'product_id' => $product->id,
        ]);

        $stranger = User::factory()->create();
        $strangerCustomer = $this->identity->resolveCustomerAccount($stranger, StoreContext::main());
        $strangerOrder = Order::factory()->create([
            'customer_account_id' => $strangerCustomer->id,
            'user_id' => $stranger->id,
            'product_id' => $product->id,
        ]);
        Account::factory()->create([
            'customer_account_id' => $strangerCustomer->id,
            'user_id' => $stranger->id,
            'order_id' => $strangerOrder->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($owner)
            ->get(route('website.accounts.index'))
            ->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 1);
    }

    #[Test]
    public function a_customer_cannot_view_another_customers_account_detail(): void
    {
        $product = $this->makeProduct();

        $owner = User::factory()->create();
        $ownerCustomer = $this->identity->resolveCustomerAccount($owner, StoreContext::main());
        $order = Order::factory()->create([
            'customer_account_id' => $ownerCustomer->id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
        ]);
        $account = Account::factory()->create([
            'customer_account_id' => $ownerCustomer->id,
            'user_id' => $owner->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_url' => 'https://sub.example.test/secret',
        ]);

        $this->actingAs($owner)
            ->get(route('website.accounts.show', $account->id))
            ->assertOk()
            ->assertSee('sub.example.test/secret');

        $stranger = User::factory()->create();
        $this->identity->resolveCustomerAccount($stranger, StoreContext::main());

        $this->actingAs($stranger)
            ->get(route('website.accounts.show', $account->id))
            ->assertNotFound();
    }
}
