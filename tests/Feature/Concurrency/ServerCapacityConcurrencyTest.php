<?php

namespace Tests\Feature\Concurrency;

use App\Models\Category;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Provisioning\ProvisioningFailedException;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\ServerSelection\LeastActiveAccountsStrategy;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز A2 سند v2.1 (بند ۶۵ — Capacity در برابر Provisioning هم‌زمان).
 *
 * مثل تست‌های Sale Limit، اینجا هم Race واقعی قابل شبیه‌سازی نیست (تک‌پردازه،
 * SQLite :memory:). برخلافِ Sale Limit، اینجا اصلاً نمی‌شود با یک نمونه‌ی
 * درون‌حافظه‌ایِ «تازه» شبیه‌سازی کرد، چون `selectAndReserve()` همیشه مستقیماً
 * از دیتابیس می‌خواند (نه از یک شیِ ServerPanel از پیش داده‌شده) — یعنی پنجره‌ی
 * Race واقعی فقط زمانی رخ می‌دهد که دو درخواست *واقعاً هم‌زمان* به یک ردیف
 * برسند، نه با فراخوانی پیاپی. پس تست اصلی همان چیزی است که در دنیای واقعی
 * ضمانتِ درستی می‌دهد: خودِ عبارتِ SQL اتمیک (`reserveCapacitySlot`) با
 * N+1 فراخوانیِ پیاپی — دقیقاً معادلِ تضمینِ ریاضیِ یک UPDATE اتمیک زیر بار
 * تراکنش‌های واقعاً هم‌زمان.
 */
class ServerCapacityConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseService $purchase;

    protected WalletService $wallet;

    protected IdentityService $identity;

    protected bool $panelHealthy = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchase = app(PurchaseService::class);
        $this->wallet = app(WalletService::class);
        $this->identity = app(IdentityService::class);

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/') && ! str_contains($url, '/template-user')) {
                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            if (! $this->panelHealthy) {
                return Http::response(['success' => false, 'msg' => 'panel down'], 500);
            }

            return Http::response(['success' => true, 'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0, 'subId' => 'sub123']], 200);
        });
    }

    protected function panel(?int $capacity = null, int $activeAccounts = 0): ServerPanel
    {
        return ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 'template-user', 'sub_base_url' => 'https://s.test/sub'],
            'capacity' => $capacity,
            'active_accounts_count' => $activeAccounts,
        ]);
    }

    protected function productOn(ServerPanel $panel): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 1000,
            'duration_days' => 30,
            'traffic_gb' => 10,
            'status' => 'active',
        ]);
    }

    protected function buyer(int $balance = 5000)
    {
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($customer, $balance);

        return $customer;
    }

    #[Test]
    public function the_atomic_reservation_never_admits_more_than_the_panels_capacity(): void
    {
        $panel = $this->panel(capacity: 3);

        $successes = 0;

        for ($i = 0; $i < 7; $i++) {
            if ($panel->reserveCapacitySlot()) {
                $successes++;
            }
        }

        $this->assertEquals(3, $successes);
        $this->assertEquals(3, $panel->fresh()->active_accounts_count);
    }

    #[Test]
    public function a_panel_without_a_capacity_limit_is_never_capped(): void
    {
        $panel = $this->panel(capacity: null);

        for ($i = 0; $i < 10; $i++) {
            $panel->reserveCapacitySlot();
        }

        $this->assertEquals(10, $panel->fresh()->active_accounts_count);
    }

    #[Test]
    public function releasing_a_reservation_frees_the_slot_for_the_next_attempt(): void
    {
        $panel = $this->panel(capacity: 1);

        $this->assertTrue($panel->reserveCapacitySlot());
        $this->assertFalse($panel->reserveCapacitySlot(), 'دومی نباید موفق شود؛ ظرفیت پر است');

        $panel->releaseCapacitySlot();
        $this->assertEquals(0, $panel->fresh()->active_accounts_count);

        $this->assertTrue($panel->reserveCapacitySlot(), 'بعد از آزادسازی باید دوباره ممکن باشد');
    }

    #[Test]
    public function select_excludes_a_panel_that_is_already_at_capacity(): void
    {
        $full = $this->panel(capacity: 1, activeAccounts: 1);
        $category = Category::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach($full->id);

        $strategy = app(LeastActiveAccountsStrategy::class);

        $this->assertNull($strategy->select($category));
        $this->assertNull($strategy->selectAndReserve($category));
    }

    #[Test]
    public function selectAndReserve_skips_a_full_candidate_and_reserves_the_next_one(): void
    {
        $full = $this->panel(capacity: 1, activeAccounts: 1);
        $free = $this->panel(capacity: 5, activeAccounts: 0);

        $category = Category::factory()->create(['status' => 'active']);
        $category->serverPanels()->attach([$full->id, $free->id]);

        $strategy = app(LeastActiveAccountsStrategy::class);
        $chosen = $strategy->selectAndReserve($category);

        $this->assertNotNull($chosen);
        $this->assertEquals($free->id, $chosen->id);
        $this->assertEquals(1, $free->fresh()->active_accounts_count);
        $this->assertEquals(1, $full->fresh()->active_accounts_count, 'پنلِ پر نباید دست بخورد');
    }

    #[Test]
    public function a_full_stack_purchase_stops_exactly_at_capacity_and_charges_nothing_for_the_rejected_one(): void
    {
        $panel = $this->panel(capacity: 2);
        $product = $this->productOn($panel);

        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();
        $buyer3 = $this->buyer();

        $this->purchase->purchase($buyer1, $product, StoreContext::main(), idempotencyKey: 'cap:1');
        $this->purchase->purchase($buyer2, $product, StoreContext::main(), idempotencyKey: 'cap:2');

        $this->assertEquals(2, $panel->fresh()->active_accounts_count);

        $this->expectException(PurchaseNotAllowedException::class);

        try {
            $this->purchase->purchase($buyer3, $product, StoreContext::main(), idempotencyKey: 'cap:3');
        } finally {
            $this->assertEquals(2, $panel->fresh()->active_accounts_count, 'نباید از ظرفیت عبور کند');
            $this->assertEquals(5000, $this->wallet->balance($buyer3), 'خریدارِ ردشده نباید کسر شود');
        }
    }

    #[Test]
    public function when_the_panel_api_fails_the_reserved_slot_is_released_not_wasted(): void
    {
        $panel = $this->panel(capacity: 1);
        $product = $this->productOn($panel);
        $buyer1 = $this->buyer();
        $buyer2 = $this->buyer();

        $this->panelHealthy = false;

        try {
            $this->purchase->purchase($buyer1, $product, StoreContext::main(), idempotencyKey: 'cap:fail:1');
            $this->fail('باید به‌خاطر شکستِ پنل شکست می‌خورد');
        } catch (ProvisioningFailedException) {
        }

        // رزرو باید آزاد شده باشد — نه این‌که با شکستِ ساخت اکانت هدر برود
        $this->assertEquals(0, $panel->fresh()->active_accounts_count);

        $this->panelHealthy = true;
        $this->purchase->purchase($buyer2, $product, StoreContext::main(), idempotencyKey: 'cap:fail:2');

        $this->assertEquals(1, $panel->fresh()->active_accounts_count);
    }

    #[Test]
    public function a_manually_selected_panel_at_capacity_is_rejected_before_touching_the_wallet(): void
    {
        // این‌جا فقط ظرفیتِ همان پنلِ صریحاً انتخاب‌شده سنجیده می‌شود
        // (نه کلِ دسته‌بندی)، پس رد شدنش همین ابتدا (پیش‌بررسیِ Guard) و
        // پیش از هر کسری اتفاق می‌افتد — به همین دلیل استثنا اینجا
        // PurchaseNotAllowedException است، نه ProvisioningFailedException.
        $panel = $this->panel(capacity: 1, activeAccounts: 1);
        $product = $this->productOn($panel);
        $buyer = $this->buyer();

        $this->expectException(PurchaseNotAllowedException::class);

        try {
            $this->purchase->purchase($buyer, $product, StoreContext::main(), manualPanel: $panel, idempotencyKey: 'cap:manual');
        } finally {
            $this->assertEquals(1, $panel->fresh()->active_accounts_count, 'رزروِ جدیدی نباید اضافه شده باشد');
            $this->assertEquals(5000, $this->wallet->balance($buyer), 'کیف‌پول نباید کسر شده باشد');
        }
    }

    #[Test]
    public function renewing_an_account_never_blocks_on_its_own_full_category(): void
    {
        // فاز A2 (بند ۶۵): از وقتی select() ظرفیت را هم می‌سنجد، بدون
        // این استثنا (checkServerAvailability=false در RenewalService)،
        // این تست دقیقاً همان رگرسیونی را نشان می‌داد که رفع شد —
        // تمدیدی که به‌خاطر پرشدنِ ظرفیتِ *دسته‌بندی* رد می‌شد، نه
        // چیزی مربوط به خودِ اکانت یا سرورش.
        $panel = $this->panel(capacity: 1, activeAccounts: 1);
        $product = $this->productOn($panel);
        $customer = $this->buyer(300000);

        $account = \App\Models\Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $panel->id,
            'panel_username' => 'cap_renew_1',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        // اگر این استثنا نبود، assertServerAvailable (بدون آگاهی از
        // manualPanel) select() را روی دسته‌بندیِ کاملاً پر صدا می‌زد و
        // با PurchaseNotAllowedException رد می‌شد.
        $renewed = app(\App\Services\Core\Renewal\RenewalService::class)
            ->renew($account, idempotencyKey: 'cap:renew-on-full-category');

        $this->assertTrue($renewed->expires_at->isFuture());
        $this->assertEquals(1, $panel->fresh()->active_accounts_count, 'تمدید نباید شمارنده‌ی ظرفیت را تغییر دهد');
    }
}
