<?php

namespace Tests\Feature\Purchase;

use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Purchase\RefundService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بند ۶۲ بلوپرینت (Commission Tests) به‌علاوه‌ی اتصال فاز G.
 */
class CommissionAndBridgeTest extends TestCase
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

            if (
                $request->method() === 'GET'
                && str_contains($url, '/panel/api/clients/get/')
            ) {
                $username = rawurldecode(
                    substr($url, strrpos($url, '/') + 1)
                );

                if ($username === 't') {
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
                    'success' => false,
                    'obj' => null,
                    'msg' => 'record not found',
                ], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => [
                    'inboundIds' => [1],
                    'flow' => '',
                    'limitIp' => 0,
                ],
            ], 200);
        });
    }

    protected function makeProduct(int $price = 100000): Product
    {
        $category = Category::factory()->create(['status' => 'active', 'available_to_resellers' => true]);

        $panel = ServerPanel::factory()->create([
            'status' => 'active',
            'panel_type' => 'sanaei',
            'credentials' => json_encode(['api_token' => 'x']),
            'extra_settings' => ['template_username' => 't', 'sub_base_url' => 'https://s.test/sub'],
        ]);

        $category->serverPanels()->attach($panel->id);

        return Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $price,
            'status' => 'active',
        ]);
    }

    protected function settings(array $values): void
    {
        AffiliateSetting::current()->update($values);
    }

    /* ── کمیسیون — بند ۳۱ ───────────────────────────────────────── */

    #[Test]
    public function a_referrer_earns_a_percentage_commission_on_a_referred_purchase(): void
    {
        $this->settings([
            'commission_percent' => 10,
            'customer_bonus_amount' => 0,
            'referrer_bonus_amount' => 0,
        ]);

        $referrer = User::factory()->create();
        $buyerUser = User::factory()->create(['referrer_id' => $referrer->id]);

        $buyer = $this->identity->resolveCustomerAccount($buyerUser, StoreContext::main());
        $this->wallet->credit($buyer, 200000);

        $this->purchase->purchase(
            $buyer, $this->makeProduct(100000), StoreContext::main(), idempotencyKey: 'comm:1'
        );

        $commission = Commission::where('type', 'ongoing_commission')->firstOrFail();

        $this->assertEquals(10000, (int) $commission->amount);
        $this->assertEquals($referrer->id, $commission->referrer_id);

        $referrerAccount = $this->identity->resolveCustomerAccount($referrer, StoreContext::main());
        $this->assertEquals(10000, $this->wallet->getBalance($referrerAccount));
    }

    /**
     * بند ۳۱: نرخ باید اسنپ‌شات شود تا تغییر بعدی تاریخچه را عوض نکند.
     */
    #[Test]
    public function the_commission_rate_is_frozen_on_the_record(): void
    {
        $this->settings(['commission_percent' => 10, 'customer_bonus_amount' => 0, 'referrer_bonus_amount' => 0]);

        $referrer = User::factory()->create();
        $buyerUser = User::factory()->create(['referrer_id' => $referrer->id]);
        $buyer = $this->identity->resolveCustomerAccount($buyerUser, StoreContext::main());
        $this->wallet->credit($buyer, 200000);

        $this->purchase->purchase(
            $buyer, $this->makeProduct(100000), StoreContext::main(), idempotencyKey: 'comm:snapshot'
        );

        // ادمین نرخ را عوض می‌کند
        $this->settings(['commission_percent' => 50]);

        $commission = Commission::where('type', 'ongoing_commission')->firstOrFail();

        $this->assertEquals(10, (int) $commission->commission_rate);
        $this->assertEquals(100000, (int) $commission->base_amount);
        $this->assertEquals(10000, (int) $commission->amount);
    }

    /**
     * بند ۳۲ — «Purchase Refund → Commission NOT reversed».
     *
     * این یک تصمیم کسب‌وکاری است، نه فراموشی؛ تست می‌شود تا کسی بعداً
     * آن را به‌عنوان باگ «درست» نکند.
     */
    #[Test]
    public function refunding_an_order_does_not_reverse_the_commission(): void
    {
        $this->settings(['commission_percent' => 10, 'customer_bonus_amount' => 0, 'referrer_bonus_amount' => 0]);

        $referrer = User::factory()->create();
        $buyerUser = User::factory()->create(['referrer_id' => $referrer->id]);
        $buyer = $this->identity->resolveCustomerAccount($buyerUser, StoreContext::main());
        $this->wallet->credit($buyer, 200000);

        $account = $this->purchase->purchase(
            $buyer, $this->makeProduct(100000), StoreContext::main(), idempotencyKey: 'comm:refund'
        );

        $referrerAccount = $this->identity->resolveCustomerAccount($referrer, StoreContext::main());
        $balanceBefore = $this->wallet->getBalance($referrerAccount);

        app(RefundService::class)->refundOrder($account->order);

        $this->assertEquals($balanceBefore, $this->wallet->getBalance($referrerAccount));
        $this->assertEquals(1, Commission::where('type', 'ongoing_commission')->count());
    }

    #[Test]
    public function a_purchase_without_a_referrer_creates_no_commission(): void
    {
        $this->settings(['commission_percent' => 10]);

        $buyer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($buyer, 200000);

        $this->purchase->purchase(
            $buyer, $this->makeProduct(100000), StoreContext::main(), idempotencyKey: 'comm:none'
        );

        $this->assertEquals(0, Commission::count());
    }

    /**
     * کمیسیون باید در همان فروشگاهی پرداخت شود که خرید در آن انجام شده —
     * نه در کیف‌پول دیگرِ همان شخص.
     */
    #[Test]
    public function commission_is_paid_into_the_wallet_of_the_store_where_the_purchase_happened(): void
    {
        $this->settings(['commission_percent' => 10, 'customer_bonus_amount' => 0, 'referrer_bonus_amount' => 0]);

        $reseller = Reseller::factory()->create(['status' => 'active']);
        $store = StoreContext::reseller($reseller);

        $referrer = User::factory()->create();
        $mainWallet = $this->identity->resolveCustomerAccount($referrer, StoreContext::main());

        $buyerUser = User::factory()->create(['referrer_id' => $referrer->id]);
        $buyer = $this->identity->resolveCustomerAccount($buyerUser, $store);

        $product = $this->makeProduct(100000);
        $product->update(['reseller_price' => 60000]);

        \App\Models\ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => 100000,
            'is_enabled' => true,
        ]);

        $this->wallet->credit($buyer, 100000);
        $this->wallet->credit($reseller, 60000);

        $this->purchase->purchase($buyer, $product, $store, 'reseller_bot', idempotencyKey: 'comm:store');

        // کیف‌پول فروشگاه اصلیِ معرف نباید لمس شده باشد
        $this->assertEquals(0, $this->wallet->getBalance($mainWallet));

        $referrerInStore = $this->identity->resolveCustomerAccount($referrer, $store);
        $this->assertEquals(10000, $this->wallet->getBalance($referrerInStore));
    }

    /* ── پاداش معرفی — بند ۳۰ و ۳۳ ──────────────────────────────── */

    #[Test]
    public function the_first_purchase_bonus_is_separate_from_commission_and_paid_once(): void
    {
        $this->settings([
            'commission_percent' => 10,
            'customer_bonus_amount' => 5000,
            'referrer_bonus_amount' => 7000,
        ]);

        $referrer = User::factory()->create();
        $buyerUser = User::factory()->create(['referrer_id' => $referrer->id]);
        $buyer = $this->identity->resolveCustomerAccount($buyerUser, StoreContext::main());
        $this->wallet->credit($buyer, 500000);

        $product = $this->makeProduct(100000);

        $this->purchase->purchase($buyer, $product, StoreContext::main(), idempotencyKey: 'bonus:1');

        $this->assertEquals(1, Commission::where('type', 'first_purchase_bonus')->count());
        $this->assertEquals(1, Commission::where('type', 'ongoing_commission')->count());

        // خرید دوم: کمیسیون بله، پاداش اولین خرید خیر
        $this->purchase->purchase($buyer, $product, StoreContext::main(), idempotencyKey: 'bonus:2');

        $this->assertEquals(1, Commission::where('type', 'first_purchase_bonus')->count());
        $this->assertEquals(2, Commission::where('type', 'ongoing_commission')->count());
    }

    /* ── فاز G — پل ─────────────────────────────────────────────── */

    /**
     * مهم‌ترین تست فاز G: مسیر قدیمی که ربات‌ها استفاده می‌کنند، بدون
     * هیچ تغییری در خودشان، حالا باید از هسته‌ی جدید عبور کند — یعنی
     * سفارشش customer_account_id و main_price داشته باشد.
     */
    #[Test]
    public function the_legacy_account_service_now_routes_through_the_new_core(): void
    {
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 300000);

        $account = app(AccountService::class)->purchase(
            $user,
            $this->makeProduct(100000),
            idempotencyKey: 'bridge:1',
        );

        $order = $account->order;

        $this->assertEquals($customer->id, $order->customer_account_id, 'سفارش باید مالک Multi-Store داشته باشد');
        $this->assertEquals(100000, (int) $order->main_price, 'اسنپ‌شات قیمت باید ثبت شده باشد');
        $this->assertEquals(1, $order->provision_attempts, 'باید از مسیر ProvisioningService عبور کرده باشد');
    }

    #[Test]
    public function the_bridge_preserves_idempotency_for_bot_callbacks(): void
    {
        $user = User::factory()->create();
        $customer = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($customer, 300000);

        $product = $this->makeProduct(100000);
        $service = app(AccountService::class);

        $first = $service->purchase($user, $product, idempotencyKey: 'bridge:dup');
        $second = $service->purchase($user, $product, idempotencyKey: 'bridge:dup');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(200000, $this->wallet->getBalance($customer));
    }
}
