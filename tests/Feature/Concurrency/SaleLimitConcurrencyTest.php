<?php

namespace Tests\Feature\Concurrency;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Purchase\PurchaseNotAllowedException;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Purchase\RefundService;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز A2 سند v2.1 (بند ۶۱ — Sale Limit در برابر خرید هم‌زمان).
 *
 * این تست‌ها Race واقعی شبیه‌سازی نمی‌کنند (Test Suite تک‌پردازه و روی
 * SQLite :memory: اجرا می‌شود؛ Thread واقعی وجود ندارد)، بلکه دو چیز
 * را جدا می‌سنجند:
 *
 *   ۱) خودِ عملیاتِ اتمیک (UPDATE شرطی) — با N+1 فراخوانیِ پیاپی، دقیقاً
 *      اثبات می‌کند که WHERE-guard هرگز بیش از سقف اجازه نمی‌دهد. این
 *      دقیقاً همان تضمینی است که زیر بار همزمانیِ واقعیِ MySQL هم برقرار
 *      می‌ماند، چون یک UPDATE اتمیک است — نه یک SELECT جدا و بعد UPDATE.
 *   ۲) شبیه‌سازیِ واقع‌گرایانه‌ی Race با استفاده از یک نمونه‌ی درون‌حافظه‌ایِ
 *      Product که بین دو خرید عمداً taze نگه داشته می‌شود — دقیقاً همان
 *      چیزی که در دو پردازه‌ی واقعیِ هم‌زمان رخ می‌دهد: هر دو Product را
 *      با units_sold قدیمی می‌خوانند، پس تنها چیزی که دومی را می‌بندد،
 *      رزروِ اتمیکِ داخل تراکنش است، نه پیش‌بررسیِ Guard.
 */
class SaleLimitConcurrencyTest extends TestCase
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

        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/') && ! str_contains($url, '/template-user')) {
                return Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            return Http::response(['success' => true, 'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0, 'subId' => 'sub123']], 200);
        });
    }

    protected function makeProduct(?int $saleLimit = null): Product
    {
        $category = Category::factory()->create(['status' => 'active']);
        $panel = ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 'template-user', 'sub_base_url' => 'https://s.test/sub'],
        ]);
        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => 1000,
            'duration_days' => 30,
            'traffic_gb' => 10,
            'status' => 'active',
            'sale_limit' => $saleLimit,
        ]);
    }

    protected function buyer(int $balance = 5000)
    {
        $customer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($customer, $balance);

        return $customer;
    }

    #[Test]
    public function the_atomic_reservation_never_admits_more_than_the_configured_limit(): void
    {
        // این تست خودِ عبارت SQL را که PurchaseService::execute استفاده
        // می‌کند مستقیماً و پیاپی صدا می‌زند — دقیقاً معادل N+1 درخواستِ
        // واقعاً هم‌زمان از منظرِ تضمینِ اتمیک‌بودنِ خودِ دستور.
        $product = Product::factory()->create(['sale_limit' => 3]);

        $successes = 0;

        for ($i = 0; $i < 7; $i++) {
            $reserved = Product::query()
                ->whereKey($product->id)
                ->where(function ($q) {
                    $q->whereNull('sale_limit')->orWhereColumn('units_sold', '<', 'sale_limit');
                })
                ->update(['units_sold' => DB::raw('units_sold + 1')]);

            if ($reserved === 1) {
                $successes++;
            }
        }

        $this->assertEquals(3, $successes);
        $this->assertEquals(3, $product->fresh()->units_sold);
    }

    #[Test]
    public function a_product_without_a_sale_limit_is_never_capped_by_the_reservation(): void
    {
        $product = Product::factory()->create(['sale_limit' => null]);

        for ($i = 0; $i < 10; $i++) {
            Product::query()
                ->whereKey($product->id)
                ->where(function ($q) {
                    $q->whereNull('sale_limit')->orWhereColumn('units_sold', '<', 'sale_limit');
                })
                ->update(['units_sold' => DB::raw('units_sold + 1')]);
        }

        $this->assertEquals(10, $product->fresh()->units_sold);
    }

    #[Test]
    public function two_purchases_racing_on_a_stale_in_memory_product_are_still_capped_at_one(): void
    {
        $product = $this->makeProduct(saleLimit: 1);
        // این fresh فقط برای اطمینان از units_sold=0 در حافظه است؛ از
        // این پس عمداً دیگر refresh نمی‌کنیم.
        $product->refresh();

        $buyerA = $this->buyer();
        $buyerB = $this->buyer();

        // هر دو خرید از همان نمونه‌ی $product استفاده می‌کنند — دقیقاً
        // شبیه‌سازیِ دو درخواستِ هم‌زمان که هر دو units_sold=0 را
        // خوانده‌اند، پیش از این‌که هیچ‌کدام commit کرده باشد.
        $this->purchase->purchase($buyerA, $product, StoreContext::main(), idempotencyKey: 'race:sale-limit:1');

        $this->expectException(PurchaseNotAllowedException::class);

        try {
            $this->purchase->purchase($buyerB, $product, StoreContext::main(), idempotencyKey: 'race:sale-limit:2');
        } finally {
            $this->assertEquals(1, Product::find($product->id)->units_sold);
            $this->assertEquals(5000, $this->wallet->balance($buyerB), 'کیف‌پول خریدارِ دوم نباید کسر شده باشد');
            $this->assertEquals(1, Order::where('product_id', $product->id)->count());
        }
    }

    #[Test]
    public function a_rejected_purchase_never_increments_the_counter(): void
    {
        $product = $this->makeProduct(saleLimit: 1);
        $buyerA = $this->buyer();
        $buyerB = $this->buyer();

        $this->purchase->purchase($buyerA, $product->fresh(), StoreContext::main(), idempotencyKey: 'reject:1');
        $this->assertEquals(1, $product->fresh()->units_sold);

        try {
            $this->purchase->purchase($buyerB, $product->fresh(), StoreContext::main(), idempotencyKey: 'reject:2');
            $this->fail('باید به‌خاطر سقف فروش رد می‌شد');
        } catch (PurchaseNotAllowedException) {
        }

        $this->assertEquals(1, $product->fresh()->units_sold, 'تلاشِ ردشده نباید شمارنده را تغییر دهد');
    }

    #[Test]
    public function refunding_an_order_releases_its_slot_for_the_next_purchase(): void
    {
        $product = $this->makeProduct(saleLimit: 1);
        $buyerA = $this->buyer();
        $buyerB = $this->buyer();

        $account = $this->purchase->purchase($buyerA, $product->fresh(), StoreContext::main(), idempotencyKey: 'refund:1');
        $this->assertEquals(1, $product->fresh()->units_sold);

        try {
            $this->purchase->purchase($buyerB, $product->fresh(), StoreContext::main(), idempotencyKey: 'refund:blocked');
            $this->fail('باید به‌خاطر سقف فروش رد می‌شد');
        } catch (PurchaseNotAllowedException) {
        }

        app(RefundService::class)->refundOrder($account->order);

        $this->assertEquals(0, $product->fresh()->units_sold, 'بازگشت وجه باید سهمیه را آزاد کند');

        // حالا خریدار دوم باید بتواند همان «واحد آزادشده» را بخرد
        $this->purchase->purchase($buyerB, $product->fresh(), StoreContext::main(), idempotencyKey: 'refund:2');

        $this->assertEquals(1, $product->fresh()->units_sold);
    }

    #[Test]
    public function renewing_an_existing_account_never_touches_the_sale_limit_counter(): void
    {
        // بدون sale_limit تا این تست فقط رفتار شمارنده را بسنجد، نه
        // رفتارِ از قبل موجودِ guard در برابر sale_limit روی تمدید (که
        // موضوعِ این فاز نیست).
        $product = $this->makeProduct(saleLimit: null);
        $customer = $this->buyer(300000);

        $account = Account::factory()->create([
            'customer_account_id' => $customer->id,
            'product_id' => $product->id,
            'server_panel_id' => $product->category->serverPanels()->first()->id,
            'panel_username' => 'sale_limit_renew_1',
            'expires_at' => now()->subDay(),
            'traffic_gb' => 10,
            'traffic_used_gb' => 8,
            'status' => 'active',
        ]);

        $this->assertEquals(0, $product->fresh()->units_sold);

        app(RenewalService::class)->renew($account, idempotencyKey: 'renew:sale-limit-untouched');

        $this->assertEquals(0, $product->fresh()->units_sold, 'تمدید نباید یک واحدِ جدید مصرف کند');
    }
}
