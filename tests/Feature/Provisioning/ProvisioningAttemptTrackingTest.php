<?php

namespace Tests\Feature\Provisioning;

use App\Models\Category;
use App\Models\Operation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProvisioningAttempt;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز A3 سند v2.1 (بند ۶۹) — اقلام ۱ تا ۷: ایجاد ProvisioningAttempt،
 * ثبت operation_id/attempt_number/status، و error/started_at/finished_at.
 *
 * پوشش سناریوهای Retry/Duplicate Retry (اقلام ۸ تا ۱۰) عمداً اینجا
 * نیست؛ فقط بررسی می‌شود که خودِ رکورد تلاش، با مقادیر درست، برای هر
 * مسیر (موفق/شکست پنل/بدون ظرفیت) ساخته و به‌روزرسانی می‌شود.
 */
class ProvisioningAttemptTrackingTest extends TestCase
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

    protected function panelSucceeds(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
                && ! str_contains($url, '/template-user')
            ) {
                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/template-user')
            ) {
                return Http::response([
                    'success' => true,
                    'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0],
                ], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0, 'subId' => 'sub123'],
            ], 200);
        });
    }

    protected function panelFails(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/')) {
                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            return Http::response(['success' => false, 'msg' => 'panel down'], 500);
        });
    }

    protected function makeProduct(bool $withPanel = true): Product
    {
        $category = Category::factory()->create(['status' => 'active']);

        if ($withPanel) {
            $panel = ServerPanel::factory()->create([
                'name' => 'Germany Frankfurt',
                'status' => 'active',
                'panel_type' => 'sanaei',
                'credentials' => json_encode(['api_token' => 'x']),
                'extra_settings' => [
                    'template_username' => 'template-user',
                    'sub_base_url' => 'https://s.test/sub',
                ],
            ]);

            $category->serverPanels()->attach($panel->id);
        }

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 100000,
            'duration_days' => 30,
            'traffic_gb' => 50,
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
    public function a_successful_provision_records_one_succeeded_attempt_linked_to_its_operation(): void
    {
        $this->panelSucceeds();

        $account = $this->purchase->purchase(
            $this->buyer(),
            $this->makeProduct(),
            StoreContext::main(),
            idempotencyKey: 'attempt:ok:1',
        );

        $order = $account->order->fresh();
        $operation = Operation::where('idempotency_key', 'attempt:ok:1')->firstOrFail();

        $attempts = $order->provisioningAttempts()->get();

        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts->first()->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_SUCCEEDED, $attempts->first()->status);
        $this->assertSame($operation->id, $attempts->first()->operation_id);
        $this->assertNull($attempts->first()->error);
        $this->assertNotNull($attempts->first()->started_at);
        $this->assertNotNull($attempts->first()->finished_at);
        $this->assertTrue($attempts->first()->finished_at->gte($attempts->first()->started_at));
    }

    #[Test]
    public function a_panel_failure_records_one_failed_attempt(): void
    {
        $this->panelFails();

        try {
            $this->purchase->purchase(
                $this->buyer(),
                $this->makeProduct(),
                StoreContext::main(),
                idempotencyKey: 'attempt:fail:1',
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();
        $attempts = $order->provisioningAttempts()->get();

        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts->first()->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $attempts->first()->status);
        $this->assertNotNull($attempts->first()->error);
        $this->assertStringContainsString('پنل', $attempts->first()->error);
        $this->assertNotNull($attempts->first()->started_at);
        $this->assertNotNull($attempts->first()->finished_at);
    }

    #[Test]
    public function no_available_panel_still_records_a_failed_attempt(): void
    {
        // بدون اتصال هیچ پنلی به دسته‌بندی، selectAndReserve همیشه null
        // برمی‌گرداند — این هم باید یک تلاشِ ثبت‌شده حساب شود (بند ۲۵).
        try {
            $this->purchase->purchase(
                $this->buyer(),
                $this->makeProduct(withPanel: false),
                StoreContext::main(),
                idempotencyKey: 'attempt:no-panel:1',
            );

            $this->fail('نبودِ پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();
        $attempts = $order->provisioningAttempts()->get();

        $this->assertCount(1, $attempts);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $attempts->first()->status);
        $this->assertNotNull($attempts->first()->error);
        $this->assertNotNull($attempts->first()->finished_at);
    }

    #[Test]
    public function retrying_a_failed_order_records_a_second_attempt_with_the_next_number(): void
    {
        $this->panelFails();

        try {
            $this->purchase->purchase(
                $this->buyer(),
                $this->makeProduct(),
                StoreContext::main(),
                idempotencyKey: 'attempt:retry:1',
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();

        $this->panelSucceeds();

        try {
            $this->purchase->retryProvisioning($order);
        } catch (ProvisioningFailedException) {
        }

        $attempts = $order->provisioningAttempts()->orderBy('attempt_number')->get();

        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $attempts[0]->status);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_SUCCEEDED, $attempts[1]->status);
    }
}
