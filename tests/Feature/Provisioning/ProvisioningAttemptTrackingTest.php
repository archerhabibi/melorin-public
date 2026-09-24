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
use App\Services\Core\Provisioning\ProvisioningService;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز A3 سند v2.1 (بند ۶۹) — اقلام ۱ تا ۱۰ (کاملِ فاز):
 * ایجاد ProvisioningAttempt، ثبت operation_id/attempt_number/status،
 * error/started_at/finished_at، تست Retry، تست Duplicate Retry، و
 * اطمینان از عدم Debit مجدد.
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

    /**
     * باگ واقعی که این helper رفع می‌کند: Http::fake() در لاراول فراخوانی‌های
     * متوالی‌اش را merge می‌کند، نه replace — و در تطبیق، اولین callback ثبت‌شده
     * که یک پاسخ (غیر null) برمی‌گرداند برنده است، نه آخرین‌ای که فراخوانی
     * شده. یعنی اگر یک تست ابتدا panelFails() (یک catch-all بدون شرط) و بعد
     * panelSucceeds() را صدا بزند، همان catch-all اولی هنوز در صفِ callbackها
     * جلوتر است و برای هر درخواستی برنده می‌ماند — panelSucceeds() عملاً
     * بی‌اثر می‌شود. راه‌حل: فقط یک‌بار Http::fake() ثبت می‌شود؛ خودِ closure در
     * لحظه‌ی هر درخواست، وضعیتِ فعلیِ پنل ($this->panelIsUp) را می‌خواند —
     * panelFails()/panelSucceeds() فقط همین پرچم را عوض می‌کنند.
     */
    protected bool $panelIsUp = true;

    protected bool $httpFaked = false;

    protected function ensurePanelHttpFaked(): void
    {
        if ($this->httpFaked) {
            return;
        }

        Http::fake(function ($request) {
            $url = $request->url();

            if (! $this->panelIsUp) {
                if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/')) {
                    return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
                }

                return Http::response(['success' => false, 'msg' => 'panel down'], 500);
            }

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

        $this->httpFaked = true;
    }

    protected function panelSucceeds(): void
    {
        $this->panelIsUp = true;
        $this->ensurePanelHttpFaked();
    }

    protected function panelFails(): void
    {
        $this->panelIsUp = false;
        $this->ensurePanelHttpFaked();
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
        // این سناریو عمداً از purchase() مستقیم با یک محصولِ بدونِ پنل
        // اجرا نمی‌شود: PurchaseGuard::assertServerAvailable() دقیقاً
        // برای همین حالت قبل از هر Debit سفارش را رد می‌کند (بند ۴۵/۶۳
        // سند — «هیچ Debit قبل از Validation کامل انجام نشود»)، یعنی
        // purchase() اصلاً به ProvisioningService نمی‌رسد و هیچ Attempt‌ی
        // ساخته نمی‌شود — که خودش رفتار درستی است.
        //
        // سناریوی واقعی‌ای که «نبودِ پنل در لحظه‌ی Provisioning» را
        // می‌سنجد retryProvisioning() است: guard دوباره صدا زده نمی‌شود
        // (فقط claimForRetry، یک قفلِ اتمیکِ روی وضعیتِ سفارش)، پس اگر
        // بینِ خریدِ اول و retry تنها پنلِ متصل به دسته‌بندی از دسترس خارج
        // شود، selectAndReserve در provision() واقعاً null برمی‌گرداند و
        // این باید یک تلاشِ ثبت‌شده‌ی FAILED باشد (بند ۶۹).
        $this->panelFails();

        $customer = $this->buyer();
        $product = $this->makeProduct();

        try {
            $this->purchase->purchase(
                $customer,
                $product,
                StoreContext::main(),
                idempotencyKey: 'attempt:no-panel:1',
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();

        // پنلِ تنها-موجود را غیرفعال می‌کنیم تا در retry هیچ پنلِ واجدِ
        // شرایطی برای دسته‌بندی باقی نماند.
        ServerPanel::query()->update(['status' => 'inactive']);

        try {
            $this->purchase->retryProvisioning($order);

            $this->fail('نبودِ پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $attempts = $order->provisioningAttempts()->orderBy('attempt_number')->get();

        $this->assertCount(2, $attempts);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $attempts[1]->status);
        $this->assertNotNull($attempts[1]->error);
        $this->assertNotNull($attempts[1]->finished_at);
    }

    #[Test]
    public function retrying_a_failed_order_succeeds_and_records_the_next_attempt_without_a_new_debit(): void
    {
        Cache::flush();
        // اقلام ۸ و ۱۰ — بند ۷۵ سند: «اگر Purchase قبلاً Debit شده باشد
        // و Provisioning Retry شود، Customer Debit = 0 در Retry».
        $this->panelFails();

        $customer = $this->buyer(500000);

        try {
            $this->purchase->purchase(
                $customer,
                $this->makeProduct(),
                StoreContext::main(),
                idempotencyKey: 'attempt:retry:1',
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();

        // Debit خرید (main_price=100000) قبلاً در مرحله‌ی ۱ (قبل از
        // Provisioning) قطعی شده — چه Provisioning موفق شود چه نه.
        $balanceAfterFailedPurchase = $this->wallet->getBalance($customer);
        $this->assertSame(400000.0, $balanceAfterFailedPurchase);

        $this->panelSucceeds();

        try {
            $this->purchase->retryProvisioning($order);
        } catch (ProvisioningFailedException) {
        }

        // اقلام ۸ — تست Retry: تلاشِ دوم با شماره‌ی درست و نتیجه‌ی درست
        // ثبت شده.
        $attempts = $order->provisioningAttempts()->orderBy('attempt_number')->get();

        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]->attempt_number);
        $this->assertSame(ProvisioningAttempt::STATUS_FAILED, $attempts[0]->status);
        $this->assertSame(2, $attempts[1]->attempt_number);
        $this->assertSame(
    ProvisioningAttempt::STATUS_SUCCEEDED,
    $attempts[1]->status,
    'Retry attempt failed: '.$attempts[1]->error
);

        // اقلام ۱۰ — عدم Debit مجدد: retry موفق، اما موجودی همان مقدارِ
        // بعد از خریدِ اول است؛ هیچ کسرِ دومی رخ نداده.
        $this->assertSame($balanceAfterFailedPurchase, $this->wallet->getBalance($customer));
    }

    #[Test]
    public function a_concurrent_duplicate_retry_is_rejected_without_a_new_attempt_or_debit(): void
    {
        // اقلام ۹ — تست Duplicate Retry: claimForRetry یک UPDATE شرطی
        // است (بند ۲۵/فاز ۱۱). این تست دقیقاً همان شرط را شبیه‌سازی
        // می‌کند: فرآیندِ اول سفارش را برای retry «claim» کرده
        // (status → provisioning) اما هنوز provision() را صدا نزده؛
        // فرآیندِ دوم (retryProvisioning تکراری) باید رد شود.
        $this->panelFails();

        $customer = $this->buyer(500000);

        try {
            $this->purchase->purchase(
                $customer,
                $this->makeProduct(),
                StoreContext::main(),
                idempotencyKey: 'attempt:dup-retry:1',
            );

            $this->fail('شکست پنل باید استثنا می‌داد.');
        } catch (ProvisioningFailedException) {
        }

        $order = Order::firstOrFail();
        $balanceAfterFailedPurchase = $this->wallet->getBalance($customer);

        // فرآیندِ اول: claim موفق (provision_failed → provisioning).
        $this->assertTrue(app(ProvisioningService::class)->claimForRetry($order));

        $this->panelSucceeds();

        // فرآیندِ دوم (تکراری/هم‌زمان): سفارش دیگر provision_failed
        // نیست، پس claim دومی رد می‌شود — نه یک ProvisioningAttempt
        // جدید ساخته می‌شود، نه Debit جدیدی می‌خورد.
        $this->expectException(PurchaseNotAllowedException::class);

        try {
            $this->purchase->retryProvisioning($order->fresh());
        } finally {
            $this->assertCount(
                1,
                $order->provisioningAttempts()->get(),
                'retry تکراری نباید ProvisioningAttempt جدیدی بسازد.'
            );

            $this->assertSame(
                $balanceAfterFailedPurchase,
                $this->wallet->getBalance($customer),
                'retry تکراری نباید Debit جدیدی ایجاد کند.'
            );
        }
    }
}
