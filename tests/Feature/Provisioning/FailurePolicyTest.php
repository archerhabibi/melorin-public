<?php

namespace Tests\Feature\Provisioning;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningSetting;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Provisioning\FailedOrderRecovery;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Provisioning\ProvisioningFailureHandler;
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * فاز ۱۱ — سیاست شکست Provisioning (retry / refund / retry_then_refund)،
 * برای خرید و تمدید، Main و نماینده.
 */
class FailurePolicyTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;

    protected bool $panelHealthy = false;

    protected PurchaseService $purchase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeTelegram();
        $this->purchase = app(PurchaseService::class);
        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);

        // یک Fake واحد که با فلگ $panelHealthy سالم/خراب می‌شود؛ چون
        // Http::fake دوم، Stub اول را override نمی‌کند.
        Http::fake(function ($request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
                && ! str_contains($url, '/template-user')
            ) {
                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            if (! $this->panelHealthy) {
                return Http::response(['success' => false, 'msg' => 'panel down'], 500);
            }

            if ($request->method() === 'GET' && str_contains($url, '/template-user')) {
                return Http::response(['success' => true, 'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0]], 200);
            }

            return Http::response(['success' => true, 'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0, 'subId' => 'sub123']], 200);
        });
    }

    protected function policy(string $policy): void
    {
        ProvisioningSetting::current()->update(['failure_policy' => $policy]);
    }

    protected function makeProduct(int $price = 100000): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = ServerPanel::factory()->create([
            'name' => 'Germany Frankfurt',
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 'template-user', 'sub_base_url' => 'https://s.test/sub'],
        ]);
        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $price,
            'duration_days' => 30,
            'traffic_gb' => 50,
            'status' => 'active',
        ]);
    }

    protected function buyer(int $balance = 500000)
    {
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($customer, $balance);

        return $customer;
    }

    protected function failedPurchase(string $key = 'p11:fail'): array
    {
        $product = $this->makeProduct();
        $customer = $this->buyer(100000);

        try {
            $this->purchase->purchase($customer, $product, StoreContext::main(), idempotencyKey: $key);
            $this->fail('باید شکست می‌خورد');
        } catch (ProvisioningFailedException $e) {
            return [$customer, Order::firstOrFail(), $e];
        }
    }

    protected function accountFor($customer, Product $product, string $username = 'germ_50_1'): Account
    {
        return Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => $username,
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);
    }

    /**
     * سفارش را «سررسیدشده» برای retry خودکار می‌کند.
     *
     * عمداً با UPDATE مستقیم روی جدول است، نه `$order->update()`: مدل درون‌حافظه‌ای
     * تست کهنه است و Eloquent فیلد datetime را با دقت «ثانیه» با مقدار اصلیِ خودش
     * مقایسه می‌کند؛ اگر دو فراخوانی پشت‌سرهم در همان ثانیه بیفتند مقدار «تغییرنکرده»
     * حساب می‌شود و هیچ UPDATE ای زده نمی‌شود، در حالی که زمان‌بندی واقعی داخل DB
     * (که handler بعد از هر شکست دوباره به آینده می‌برد) عوض نشده — نتیجه: تلاش دوم
     * اجرا نمی‌شد و provision_attempts روی ۲ می‌ماند.
     */
    protected function makeDue(Order $order): void
    {
        Order::query()->whereKey($order->id)->update(['next_provision_retry_at' => now()->subMinute()]);
    }

    #[Test]
    public function retry_policy_keeps_the_charge_and_schedules_an_automatic_retry(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order, $e] = $this->failedPurchase();

        $this->assertEquals(0, $this->wallet->getBalance($customer));
        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->status);
        $this->assertNotNull($order->next_provision_retry_at);
        $this->assertEquals(ProvisioningFailureHandler::OUTCOME_RETRY_SCHEDULED, $e->outcome());
        $this->assertDatabaseMissing('wallet_transactions', ['type' => 'refund']);
    }

    #[Test]
    public function refund_policy_refunds_immediately_from_the_order_snapshot(): void
    {
        $this->policy(ProvisioningSetting::POLICY_REFUND);

        [$customer, $order, $e] = $this->failedPurchase();

        $this->assertEquals(100000, $this->wallet->getBalance($customer));
        $this->assertEquals(Order::STATUS_REFUNDED, $order->status);
        $this->assertEquals(ProvisioningFailureHandler::OUTCOME_REFUNDED, $e->outcome());
        $this->assertStringContainsString('بازگردانده', $e->customerNotice());
    }

    #[Test]
    public function refund_policy_on_a_reseller_order_refunds_both_sides(): void
    {
        $this->policy(ProvisioningSetting::POLICY_REFUND);

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $product = $this->makeProduct(150000);
        $product->update(['reseller_price' => 100000]);
        ResellerProductPrice::create([
            'reseller_id' => $reseller->id, 'product_id' => $product->id,
            'customers_price' => 130000, 'is_enabled' => true,
        ]);

        $store = StoreContext::reseller($reseller);
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), $store);
        $this->wallet->credit($customer, 130000);
        $this->wallet->credit($reseller, 100000);

        try {
            $this->purchase->purchase($customer, $product, $store, 'reseller_bot', idempotencyKey: 'p11:reseller');
            $this->fail('باید شکست می‌خورد');
        } catch (ProvisioningFailedException) {
        }

        $this->assertEquals(130000, $this->wallet->getBalance($customer));
        $this->assertEquals(100000, $this->wallet->getBalance($reseller));
        $this->assertEquals(Order::STATUS_REFUNDED, Order::firstOrFail()->status);
    }

    #[Test]
    public function retry_then_refund_retries_first_then_refunds_after_the_last_attempt(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY_THEN_REFUND);

        [$customer, $order] = $this->failedPurchase();

        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->status);
        $this->assertEquals(0, $this->wallet->getBalance($customer));

        foreach ([2, 3] as $attempt) {
            $this->makeDue($order);


            $this->artisan('provisioning:retry-failed')->assertExitCode(0);

        }
    }
    #[Test]
    public function retry_policy_never_refunds_after_the_attempts_are_exhausted(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order] = $this->failedPurchase();

       foreach ([2, 3] as $attempt) {
            $order->refresh();
            $this->makeDue($order);


            $this->artisan('provisioning:retry-failed')->assertExitCode(0);


        }

        $order->refresh();

        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->status);
        $this->assertEquals(3, $order->provision_attempts);
        $this->assertNull($order->next_provision_retry_at);
        $this->assertEquals(0, $this->wallet->getBalance($customer));
        }
    #[Test]
    public function the_scheduled_retry_recovers_the_order_without_charging_again(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order] = $this->failedPurchase();

        $this->panelHealthy = true;
        $this->makeDue($order);

        $this->artisan('provisioning:retry-failed')->assertExitCode(0);

        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);
        $this->assertEquals(1, Account::count());
        $this->assertEquals(0, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function orders_not_yet_due_or_without_a_schedule_are_left_alone(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [, $order] = $this->failedPurchase();
        $this->panelHealthy = true;

        $this->artisan('provisioning:retry-failed')->assertExitCode(0);
        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->fresh()->status);

        $order->update(['next_provision_retry_at' => null]);
        $this->artisan('provisioning:retry-failed')->assertExitCode(0);
        $this->assertEquals(Order::STATUS_PROVISION_FAILED, $order->fresh()->status);
    }

    #[Test]
    public function a_second_claim_for_the_same_failed_order_is_rejected(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [, $order] = $this->failedPurchase();
        $provisioning = app(ProvisioningService::class);

        $this->assertTrue($provisioning->claimForRetry($order));
        $this->assertFalse($provisioning->claimForRetry(Order::findOrFail($order->id)));
    }

    #[Test]
    public function a_failed_renewal_follows_the_refund_policy(): void
    {
        $this->policy(ProvisioningSetting::POLICY_REFUND);

        $product = $this->makeProduct();
        $customer = $this->buyer(100000);
        $account = $this->accountFor($customer, $product);

        try {
            app(RenewalService::class)->renew($account, idempotencyKey: 'p11:renew:refund');
            $this->fail('باید شکست می‌خورد');
        } catch (RenewalFailedException $e) {
            $this->assertEquals(ProvisioningFailureHandler::OUTCOME_REFUNDED, $e->outcome());
        }

       $order = Order::where('renews_account_id', $account->id)->firstOrFail();

        $this->assertEquals($account->id, $order->renews_account_id);
        $this->assertEquals(Order::STATUS_REFUNDED, $order->status);
        $this->assertEquals(100000, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function a_failed_renewal_is_retried_on_the_same_account_without_a_new_charge(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        $product = $this->makeProduct();
        $customer = $this->buyer(100000);
        $account = $this->accountFor($customer, $product);

        try {
            app(RenewalService::class)->renew($account, idempotencyKey: 'p11:renew:retry');
            $this->fail('باید شکست می‌خورد');
        } catch (RenewalFailedException) {
        }

        $order = Order::where('renews_account_id', $account->id)->firstOrFail();
        $this->assertNotNull($order->next_provision_retry_at);
        $this->assertEquals(0, $this->wallet->getBalance($customer));

        $this->panelHealthy = true;
        $this->makeDue($order);

        $this->artisan('provisioning:retry-failed')->assertExitCode(0);

        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);
        $this->assertEquals(1, Account::count(), 'تمدید نباید اکانت جدید بسازد');
        $this->assertEquals(0, $this->wallet->getBalance($customer));
        $this->assertTrue($account->fresh()->expires_at->isFuture());
        $this->assertEquals(0, (int) $account->fresh()->traffic_used_gb);
    }

    #[Test]
    public function purchase_retry_refuses_a_renewal_order(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        $product = $this->makeProduct();
        $customer = $this->buyer(100000);
        $account = $this->accountFor($customer, $product);

        try {
            app(RenewalService::class)->renew($account, idempotencyKey: 'p11:renew:guard');
        } catch (RenewalFailedException) {
        }

        $order = Order::firstOrFail();

        $this->expectException(\App\Services\Core\Purchase\PurchaseNotAllowedException::class);

        $this->purchase->retryProvisioning($order, force: true);
    }

    #[Test]
    public function an_admin_can_force_a_retry_beyond_the_attempt_cap_and_refund_manually(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order] = $this->failedPurchase();
        $order->update(['provision_attempts' => 3, 'next_provision_retry_at' => null]);

        $recovery = app(FailedOrderRecovery::class);

        $this->assertEquals('skipped', $recovery->retry($order->fresh())->status);

        $this->panelHealthy = true;
        $this->assertTrue($recovery->retry($order->fresh(), force: true)->succeeded());
        $this->assertEquals(Order::STATUS_ACCOUNT_CREATED, $order->fresh()->status);

        // سفارشِ تحویل‌شده دیگر با دکمه‌ی بازگشتِ «ساخت ناموفق» قابل بازگشت نیست
        $this->assertEquals('skipped', $recovery->refund($order->fresh())->status);
        $this->assertEquals(0, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function an_admin_manual_refund_returns_the_money_once(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order] = $this->failedPurchase();
        $recovery = app(FailedOrderRecovery::class);

        $this->assertTrue($recovery->refund($order)->succeeded());
        $this->assertEquals(100000, $this->wallet->getBalance($customer));

        $this->assertFalse($recovery->refund($order->fresh())->succeeded());
        $this->assertEquals(100000, $this->wallet->getBalance($customer));
        $this->assertNull($order->fresh()->next_provision_retry_at);
    }

    #[Test]
    public function the_customer_is_notified_when_a_background_retry_delivers_the_service(): void
    {
        $this->policy(ProvisioningSetting::POLICY_RETRY);

        [$customer, $order] = $this->failedPurchase();
        $customer->user->update(['telegram_id' => 777001]);

        $sent = [];
        $this->mock(Api::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendMessage')->andReturnUsing(function ($params) use (&$sent) {
                $sent[] = $params;

                return $this->fakeMessage();
            });
        });

        $this->panelHealthy = true;
        $this->makeDue($order);

        $this->artisan('provisioning:retry-failed')->assertExitCode(0);

        $this->assertCount(1, $sent);
        $this->assertEquals(777001, $sent[0]['chat_id']);
    }
}
