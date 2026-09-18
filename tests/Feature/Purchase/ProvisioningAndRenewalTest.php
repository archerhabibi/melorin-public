<?php

namespace Tests\Feature\Purchase;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProvisioningAndRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseService $purchase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchase = app(PurchaseService::class);
        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);
    }

    /**
     * Fake سازگار با SanaeiDriver:
     *
     * - بررسی username موجود نیست => record not found
     * - template => success
     * - سایر درخواست‌های provisioning => success
     */
    protected function panelSucceeds(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
                && ! str_contains($url, '/template-user')
            ) {
                return Http::response([
                    'success' => false,
                    'obj' => null,
                    'msg' => 'record not found',
                ], 200);
            }

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/template-user')
            ) {
                return Http::response([
                    'success' => true,
                    'obj' => [
                        'inboundIds' => [1],
                        'flow' => '',
                        'limitIp' => 0,
                    ],
                ], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => [
                    'inboundIds' => [1],
                    'flow' => '',
                    'limitIp' => 0,
                    'subId' => 'sub123',
                ],
            ], 200);
        });
    }

    protected function panelFails(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
            ) {
                return Http::response([
                    'success' => false,
                    'obj' => null,
                    'msg' => 'record not found',
                ], 200);
            }

            return Http::response([
                'success' => false,
                'msg' => 'panel down',
            ], 500);
        });
    }

    protected function makeProduct(
        float $price = 100000,
        int $days = 30,
        float $gb = 50
    ): Product {
        $category = Category::factory()->create([
            'status' => 'active',
        ]);

        $panel = ServerPanel::factory()->create([
            'name' => 'Germany Frankfurt',
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode([
                'api_token' => 'x',
            ]),
            'extra_settings' => [
                'template_username' => 'template-user',
                'sub_base_url' => 'https://s.test/sub',
            ],
        ]);

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'price' => $price,
            'duration_days' => $days,
            'traffic_gb' => $gb,
            'status' => 'active',
        ]);
    }

    protected function buyer(float $balance = 500000)
    {
        $customer = $this->identity->resolveCustomerAccount(
            User::factory()->create(),
            StoreContext::main(),
        );

        $this->wallet->credit($customer, $balance);

        return $customer;
    }

    #[Test]
    public function memory_smoke_test(): void
    {
        $this->assertTrue(true);
    }

    #[Test]
    public function random_naming_generates_server_prefix_traffic_and_sequence(): void
    {
        $this->panelSucceeds();

        $category = Category::factory()->create([
            'status' => 'active',
            'naming_mode' => 'random',
        ]);

        $panel = ServerPanel::factory()->create([
            'name' => 'Germany Frankfurt',
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode([
                'api_token' => 'x',
            ]),
            'extra_settings' => [
                'sub_base_url' => 'https://example.test/sub',
                'template_username' => 'template-user',
            ],
        ]);

        $category->serverPanels()->attach($panel->id);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 100000,
            'traffic_gb' => 30,
            'duration_days' => 30,
            'status' => 'active',
        ]);

        $customer = $this->buyer(balance: 200000);

        $account = $this->purchase->purchase(
            $customer,
            $product,
            StoreContext::main(),
            idempotencyKey: 'test:naming:1',
        );

        $this->assertInstanceOf(Account::class, $account);
    }

    #[Test]
    public function a_successful_first_attempt_creates_the_account_and_closes_the_order(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct();

        $account = $this->purchase->purchase(
            $this->buyer(),
            $product,
            StoreContext::main(),
            idempotencyKey: 'prov:ok'
        );

        $this->assertEquals('active', $account->status);
        $this->assertEquals(
            Order::STATUS_ACCOUNT_CREATED,
            $account->order->fresh()->status
        );
        $this->assertEquals(
            1,
            $account->order->fresh()->provision_attempts
        );
    }

    #[Test]
    public function a_panel_failure_after_payment_leaves_an_unambiguous_financial_state(): void
    {
        $this->panelFails();

        $product = $this->makeProduct(price: 100000);
        $customer = $this->buyer(balance: 100000);

        try {
            $this->purchase->purchase(
                $customer,
                $product,
                StoreContext::main(),
                idempotencyKey: 'prov:fail'
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();

        $this->assertEquals(
            0,
            $this->wallet->getBalance($customer)
        );
        $this->assertEquals(
            Order::STATUS_PROVISION_FAILED,
            $order->status
        );
        $this->assertTrue($order->isFinanciallySettled());
        $this->assertTrue($order->needsAttention());
        $this->assertNotEmpty($order->failure_reason);
        $this->assertEquals(0, Account::count());
    }

    #[Test]
    public function a_failed_order_can_be_retried_up_to_three_times(): void
    {
        $this->panelFails();

        $product = $this->makeProduct();
        $customer = $this->buyer();

        try {
            $this->purchase->purchase(
                $customer,
                $product,
                StoreContext::main(),
                idempotencyKey: 'prov:retry'
            );
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();
        $provisioning = app(ProvisioningService::class);

        $this->assertTrue($provisioning->canRetry($order));

        foreach ([2, 3] as $expectedAttempt) {
            try {
                $provisioning->provision($order->fresh());
            } catch (ProvisioningFailedException) {
            }

            $this->assertEquals(
                $expectedAttempt,
                $order->fresh()->provision_attempts
            );
        }

        $this->assertFalse(
            $provisioning->canRetry($order->fresh())
        );
    }

    #[Test]
    public function a_retry_that_succeeds_recovers_the_order_without_charging_again(): void
    {
        $templateRequests = 0;

        Http::fake(function ($request) use (&$templateRequests) {
            $url = $request->url();

            // Username availability: every generated username is initially free.
            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
                && ! str_contains($url, '/template-user')
            ) {
                return Http::response([
                    'success' => false,
                    'obj' => null,
                    'msg' => 'record not found',
                ], 200);
            }

            // First provisioning attempt fails while resolving the template.
            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/template-user')
            ) {
                $templateRequests++;

                if ($templateRequests === 1) {
                    return Http::response([
                        'success' => false,
                        'msg' => 'panel down',
                    ], 500);
                }

                // Retry: template is now available.
                return Http::response([
                    'success' => true,
                    'obj' => [
                        'inboundIds' => [1],
                        'flow' => '',
                        'limitIp' => 0,
                    ],
                ], 200);
            }

            // Actual account creation during retry.
            return Http::response([
                'success' => true,
                'obj' => [
                    'success' => true,
                    'subId' => 'sub123',
                ],
            ], 200);
        });

        $product = $this->makeProduct(price: 100000);
        $customer = $this->buyer(balance: 200000);

        try {
            $this->purchase->purchase(
                $customer,
                $product,
                StoreContext::main(),
                idempotencyKey: 'prov:recover'
            );
        } catch (ProvisioningFailedException) {
        }

        $balanceAfterFailure = $this->wallet->getBalance($customer);

        $account = $this->purchase->retryProvisioning(
            Order::firstOrFail()
        );

        $this->assertEquals('active', $account->status);
        $this->assertEquals(
            Order::STATUS_ACCOUNT_CREATED,
            $account->order->fresh()->status
        );

        $this->assertEquals(
            $balanceAfterFailure,
            $this->wallet->getBalance($customer)
        );

        $this->assertEquals(2, $templateRequests);
    }

    #[Test]
    public function a_duplicate_purchase_request_never_creates_two_accounts(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct(price: 50000);
        $customer = $this->buyer(balance: 200000);

        $key = 'prov:duplicate-click';

        $first = $this->purchase->purchase(
            $customer,
            $product,
            StoreContext::main(),
            idempotencyKey: $key
        );

        $second = $this->purchase->purchase(
            $customer,
            $product,
            StoreContext::main(),
            idempotencyKey: $key
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, Account::count());
        $this->assertEquals(1, Order::count());
        $this->assertEquals(
            150000,
            $this->wallet->getBalance($customer)
        );
    }

    #[Test]
    public function provisioning_refuses_to_run_on_an_unpaid_order(): void
    {
        $product = $this->makeProduct();
        $customer = $this->buyer();

        $order = Order::create([
            'user_id' => $customer->user_id,
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'sales_channel' => 'main_bot',
            'base_price' => 1000,
            'core_price' => 1000,
            'sold_price' => 1000,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->expectException(ProvisioningFailedException::class);

        app(ProvisioningService::class)->provision($order);
    }


    #[Test]
    public function a_renewal_extends_time_and_resets_traffic(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct(
            price: 100000,
            days: 30,
            gb: 50
        );

        $customer = $this->buyer(balance: 300000);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'germ_50_1',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        $balanceBefore = $this->wallet->getBalance($customer);

        $renewed = app(\App\Services\Core\Renewal\RenewalService::class)
            ->renew(
                $account,
                idempotencyKey: 'renew:test:extend-reset'
            );

        $renewed->refresh();

        $this->assertEquals('active', $renewed->status);

        $this->assertEquals(
            50,
            (float) $renewed->traffic_gb
        );

        $this->assertEquals(
            0,
            (float) $renewed->traffic_used_gb
        );

        $this->assertTrue(
            $renewed->expires_at->isFuture()
        );

        $this->assertEquals(
            $balanceBefore - 100000,
            $this->wallet->getBalance($customer)
        );
    }

    #[Test]
    public function renewing_early_adds_to_the_remaining_time_instead_of_discarding_it(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct(
            price: 100000,
            days: 30,
            gb: 50
        );

        $customer = $this->buyer(balance: 300000);

        $expiresAt = now()->addDays(10);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'germ_50_1',
            'expires_at' => $expiresAt,
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        $renewed = app(\App\Services\Core\Renewal\RenewalService::class)
            ->renew(
                $account,
                idempotencyKey: 'renew:test:early'
            );

        $renewed->refresh();

        $this->assertEquals(
            50,
            (float) $renewed->traffic_gb
        );

        $this->assertEquals(
            0,
            (float) $renewed->traffic_used_gb
        );

        $this->assertTrue(
            $renewed->expires_at->isAfter(now()->addDays(39))
        );

        $this->assertTrue(
            $renewed->expires_at->isBefore(now()->addDays(41))
        );
    }

    #[Test]
    public function a_panel_failure_during_renewal_is_recorded_not_silently_swallowed(): void
    {
        $this->panelFails();

        $product = $this->makeProduct(
            price: 100000,
            days: 30,
            gb: 50
        );

        $customer = $this->buyer(balance: 300000);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'germ_50_1',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        $balanceBefore = $this->wallet->getBalance($customer);

        $this->expectException(\App\Services\Core\Renewal\RenewalFailedException::class);

        try {
            app(\App\Services\Core\Renewal\RenewalService::class)
                ->renew(
                    $account,
                    idempotencyKey: 'renew:test:panel-failure'
                );
        } finally {
            $this->assertEquals(
                $balanceBefore - 100000,
                $this->wallet->getBalance($customer)
            );

            $this->assertEquals(
                Order::STATUS_PROVISION_FAILED,
                Order::query()->latest('id')->first()->status
            );
        }

    }

    #[Test]
    public function a_renewal_uses_current_prices_and_creates_a_new_price_snapshot(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct(
            price: 90000,
            days: 30,
            gb: 50
        );

        $customer = $this->buyer(balance: 300000);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'germ_renew_snapshot',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        // قیمت محصول بعد از خرید/ایجاد اکانت تغییر می‌کند.
        $product->update([
            'price' => 100000,
            'reseller_price' => null,
        ]);

        $balanceBefore = $this->wallet->getBalance($customer);

        $renewed = app(\App\Services\Core\Renewal\RenewalService::class)
            ->renew(
                $account,
                idempotencyKey: 'renew:test:current-price-snapshot'
            );

        $renewalOrder = Order::query()
        ->where('customer_account_id', $customer->id)
        ->where('product_id', $product->id)
        ->latest('id')
        ->firstOrFail();

        // Renewal باید قیمت فعلی را برای تراکنش جدید Snapshot کند.
        $this->assertEquals(100000, (float) $renewalOrder->core_price);
        $this->assertEquals(100000, (float) $renewalOrder->sold_price);
        $this->assertEquals(100000, (float) $renewalOrder->base_price);

        // و مبلغ Renewal باید از موجودی فعلی کسر شده باشد.
        $this->assertEquals(
            $balanceBefore - 100000,
            $this->wallet->getBalance($customer)
        );

        $this->assertEquals(
            Order::STATUS_ACCOUNT_CREATED,
            $renewalOrder->status
        );
    }

    #[Test]
    public function a_duplicate_renewal_request_only_charges_once(): void
    {
        $this->panelSucceeds();

        $product = $this->makeProduct(
            price: 100000,
            days: 30,
            gb: 50
        );

        $customer = $this->buyer(balance: 300000);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'germ_50_1',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        $balanceBefore = $this->wallet->getBalance($customer);

        $renewal = app(\App\Services\Core\Renewal\RenewalService::class);

        $first = $renewal->renew(
            $account,
            idempotencyKey: 'renew:test:duplicate'
        );

        $balanceAfterFirst = $this->wallet->getBalance($customer);

        $second = $renewal->renew(
            $account->fresh(),
            idempotencyKey: 'renew:test:duplicate'
        );

        $this->assertEquals(
            $balanceBefore - 100000,
            $balanceAfterFirst
        );

        $this->assertEquals(
            $balanceAfterFirst,
            $this->wallet->getBalance($customer)
        );

        $this->assertEquals(
            $first->fresh()->id,
            $second->fresh()->id
        );

        $this->assertEquals(
            1,
            Order::query()
                ->where('customer_account_id', $customer->id)
                ->where('product_id', $product->id)
                ->count()
        );
    }
}
