<?php

namespace Tests\Feature\Architecture;

use App\Exceptions\ResellerScopeViolationException;
use App\Models\Category;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Core\Purchase\PurchaseService;
use App\Services\Core\Purchase\RefundService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * مرحله‌ی ۱۳ سند (Tests) — سناریوهای خودِ سند، با همان اعدادِ بند ۵۱:
 *
 *     main_price = 12 ، reseller_price = 10
 *     customers_price: Reseller A = 14 ، Reseller C = 16
 *
 * هر تست به بند/Rule مربوط در docs/history/PHASE-14-FINAL-MODEL-TESTS.md نگاشته شده.
 */
class FinalModelSpecTest extends TestCase
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

            if ($request->method() === 'GET' && str_contains($url, '/panel/api/clients/get/')) {
                $username = rawurldecode(substr($url, strrpos($url, '/') + 1));

                return $username === 't'
                    ? Http::response(['success' => true, 'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0]], 200)
                    : Http::response(['success' => false, 'obj' => null, 'msg' => 'record not found'], 200);
            }

            return Http::response([
                'success' => true,
                'obj' => ['inboundIds' => [1], 'flow' => '', 'limitIp' => 0],
                'subscription_url' => 'https://sub.example.test/abc',
            ], 200);
        });
    }

    protected function product(int $mainPrice = 12, int $resellerPrice = 10): Product
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
            'main_price' => $mainPrice,
            'reseller_price' => $resellerPrice,
            'status' => 'active',
        ]);
    }

    protected function reseller(Product $product, int $customersPrice): Reseller
    {
        $reseller = Reseller::factory()->create(['status' => 'active']);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        return $reseller;
    }

    protected function balance(CustomerAccount|Reseller $owner): float
    {
        return $this->wallet->balance($owner);
    }

    #[Test]
    public function the_documents_own_financial_example_holds_for_main_reseller_a_and_reseller_c(): void
    {
        // بند ۸، ۹، ۱۰، ۱۵، ۱۶، ۱۷، ۵۱ و Rule 2/3/4/9/10/11
        $product = $this->product(mainPrice: 12, resellerPrice: 10);
        $a = $this->reseller($product, 14);
        $c = $this->reseller($product, 16);

        $ali = User::factory()->create();
        $aliMain = $this->identity->resolveCustomerAccount($ali, StoreContext::main());
        $aliInA = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($a));
        $aliInC = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($c));

        foreach ([$aliMain, $aliInA, $aliInC, $a, $c] as $owner) {
            $this->wallet->credit($owner, 100);
        }

        // ── Ali → Main: فقط main_price از Wallet Main ─────────────────────
        $this->purchase->purchase($aliMain, $product, StoreContext::main(), idempotencyKey: 'spec:main');

        $this->assertEquals(88, $this->balance($aliMain));
        $this->assertEquals(100, $this->balance($aliInA));
        $this->assertEquals(100, $this->balance($aliInC));
        $this->assertEquals(100, $this->balance($a), 'Debit دومی برای Main Purchase وجود ندارد');
        $this->assertEquals(100, $this->balance($c));

        $mainOrder = Order::where('reseller_id', null)->firstOrFail();
        $this->assertEquals(12, (int) $mainOrder->main_price);
        $this->assertNull($mainOrder->reseller_price);
        $this->assertNull($mainOrder->customers_price);

        // ── Ali → Reseller A: -14 از Ali/A و -10 از Wallet صاحبِ A در Main ─
        $this->purchase->purchase($aliInA, $product, StoreContext::reseller($a), 'reseller_bot', idempotencyKey: 'spec:a');

        $this->assertEquals(86, $this->balance($aliInA), 'Customer Debit = customers_price');
        $this->assertEquals(90, $this->balance($a), 'Reseller Debit = reseller_price از Main Wallet صاحب');
        $this->assertEquals(88, $this->balance($aliMain), 'Wallet Main دست‌نخورده');
        $this->assertEquals(100, $this->balance($aliInC));
        $this->assertEquals(100, $this->balance($c));

        $orderA = Order::where('reseller_id', $a->id)->firstOrFail();
        $this->assertNull($orderA->main_price);
        $this->assertEquals(10, (int) $orderA->reseller_price);
        $this->assertEquals(14, (int) $orderA->customers_price);
        $this->assertEquals(4, (int) $orderA->customers_price - (int) $orderA->reseller_price, 'reseller_profit');

        // ── Ali → Reseller C: -16 و -10 ─────────────────────────────────
        $this->purchase->purchase($aliInC, $product, StoreContext::reseller($c), 'reseller_bot', idempotencyKey: 'spec:c');

        $this->assertEquals(84, $this->balance($aliInC));
        $this->assertEquals(90, $this->balance($c));
        $this->assertEquals(90, $this->balance($a), 'خرید از C به Wallet صاحب A ربطی ندارد');
        $this->assertEquals(86, $this->balance($aliInA));

        $orderC = Order::where('reseller_id', $c->id)->firstOrFail();
        $this->assertEquals(6, (int) $orderC->customers_price - (int) $orderC->reseller_price);
    }

    #[Test]
    public function both_reseller_debits_are_attributable_and_share_one_operation(): void
    {
        // بند ۳۳ و ۳۴: هر Debit به user / wallet / context / order / operation قابل انتساب و اتمیک است
        $product = $this->product();
        $a = $this->reseller($product, 14);
        $ali = User::factory()->create();
        $aliInA = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($a));

        $this->wallet->credit($aliInA, 100);
        $this->wallet->credit($a, 100);

        $this->purchase->purchase($aliInA, $product, StoreContext::reseller($a), 'reseller_bot', idempotencyKey: 'spec:trace');

        $order = Order::firstOrFail();
        $debits = WalletTransaction::where('type', 'purchase')->get();

        $this->assertCount(2, $debits, 'دو Debit مستقل (بند ۱۹)');
        $this->assertCount(1, $debits->pluck('operation_id')->unique(), 'هر دو زیر یک Operation');
        $this->assertNotNull($debits->first()->operation_id);

        $customerDebit = $debits->firstWhere('amount', -14);
        $supplyDebit = $debits->firstWhere('amount', -10);

        $this->assertNotNull($customerDebit);
        $this->assertNotNull($supplyDebit);

        $customerWallet = $customerDebit->wallet;
        $this->assertEquals($ali->id, $customerWallet->user_id);
        $this->assertEquals('reseller', $customerWallet->store_type);
        $this->assertEquals($a->id, $customerWallet->reseller_id);

        $supplyWallet = $supplyDebit->wallet;
        $this->assertEquals($a->user_id, $supplyWallet->user_id);
        $this->assertEquals('main', $supplyWallet->store_type);
        $this->assertNull($supplyWallet->reseller_id);

        foreach ($debits as $debit) {
            $this->assertEquals($order->id, $debit->reference_id);
            $this->assertEquals(Order::class, $debit->reference_type);
        }
    }

    #[Test]
    public function refunds_use_each_orders_price_snapshot_not_todays_prices(): void
    {
        // بند ۳۸، Rule 16
        $product = $this->product(mainPrice: 12, resellerPrice: 10);
        $a = $this->reseller($product, 14);
        $refunds = app(RefundService::class);

        $ali = User::factory()->create();
        $aliMain = $this->identity->resolveCustomerAccount($ali, StoreContext::main());
        $aliInA = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($a));

        foreach ([$aliMain, $aliInA, $a] as $owner) {
            $this->wallet->credit($owner, 100);
        }

        $this->purchase->purchase($aliMain, $product, StoreContext::main(), idempotencyKey: 'spec:refund:main');
        $this->purchase->purchase($aliInA, $product, StoreContext::reseller($a), 'reseller_bot', idempotencyKey: 'spec:refund:a');

        // قیمت‌های امروز عوض می‌شوند
        $product->update(['main_price' => 99, 'reseller_price' => 77]);
        ResellerProductPrice::where('reseller_id', $a->id)->update(['customers_price' => 88]);

        $refunds->refundOrder(Order::where('reseller_id', null)->firstOrFail(), 'spec');
        $refunds->refundOrder(Order::where('reseller_id', $a->id)->firstOrFail(), 'spec');

        $this->assertEquals(100, $this->balance($aliMain), 'Main refund = main_price (12)');
        $this->assertEquals(100, $this->balance($aliInA), 'Reseller customer refund = customers_price (14)');
        $this->assertEquals(100, $this->balance($a), 'Reseller owner refund = reseller_price (10)');
    }

    #[Test]
    public function a_customer_of_one_reseller_cannot_buy_through_another_and_nothing_is_debited(): void
    {
        // بند ۶ و ۴۳: Scope
        $product = $this->product();
        $a = $this->reseller($product, 14);
        $c = $this->reseller($product, 16);

        $ali = User::factory()->create();
        $aliInC = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($c));

        $this->wallet->credit($aliInC, 100);
        $this->wallet->credit($a, 100);

        try {
            $this->purchase->purchase($aliInC, $product, StoreContext::reseller($a), 'reseller_bot', idempotencyKey: 'spec:scope');
            $this->fail('باید نقض Scope اعلام می‌شد');
        } catch (ResellerScopeViolationException) {
        }

        $this->assertEquals(100, $this->balance($aliInC));
        $this->assertEquals(100, $this->balance($a));
        $this->assertEquals(0, Order::count());
    }

    #[Test]
    public function a_reseller_owner_stays_a_direct_main_customer(): void
    {
        // Rule 13
        $product = $this->product(mainPrice: 12, resellerPrice: 10);
        $reseller = $this->reseller($product, 14);

        $ownerMain = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::main());
        $this->wallet->credit($ownerMain, 100);

        $this->purchase->purchase($ownerMain, $product, StoreContext::main(), idempotencyKey: 'spec:owner-main');

        $order = Order::firstOrFail();

        $this->assertNull($order->reseller_id, 'خرید در Main Context ثبت می‌شود');
        $this->assertNotNull($order->main_price);
        $this->assertNull($order->customers_price);
        $this->assertEquals(100 - (int) $order->main_price, $this->balance($ownerMain));
    }

    #[Test]
    public function activating_a_reseller_creates_no_new_user_and_keeps_the_main_wallet(): void
    {
        // Rule 1، Rule 14، Rule 6
        $user = User::factory()->create();
        $mainAccount = $this->identity->resolveCustomerAccount($user, StoreContext::main());
        $this->wallet->credit($mainAccount, 500);

        $usersBefore = User::count();
        $walletsBefore = Wallet::count();

        $reseller = app(ResellerService::class)->create($user, ['bot_token' => 'spec-token', 'slug' => 'spec-shop']);

        $this->assertEquals($usersBefore, User::count(), 'User جدیدی ساخته نمی‌شود');
        $this->assertEquals($user->id, $reseller->user_id, 'شناسه‌ی User تغییر نمی‌کند');
        $this->assertEquals($mainAccount->id, $this->identity->resolveCustomerAccount($user, StoreContext::main())->id);
        $this->assertEquals($walletsBefore, Wallet::count(), 'Wallet جدیدی ساخته نمی‌شود');
        $this->assertEquals(500, $this->balance($reseller), 'Wallet تأمین نماینده = همان Wallet Main کاربر');
    }

    #[Test]
    public function no_forbidden_wallet_entity_name_remains(): void
    {
        // بند ۴۹
        $pattern = '/\b(customer_wallet|reseller_wallet|main_customer_wallet|customer_reseller_wallet|customer_wallet_main)\b/';
        $self = realpath(__FILE__);
        $offenders = [];

        foreach (['app', 'resources', 'routes', 'config', 'tests', 'database/factories', 'database/seeders'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || realpath($file->getPathname()) === $self || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' → '.$m[1];
                }
            }
        }

        $this->assertSame([], $offenders, "نام ممنوعه‌ی Wallet باقی مانده:\n".implode("\n", $offenders));
    }

    #[Test]
    public function a_user_can_be_a_customer_of_two_resellers_and_buy_from_both(): void
    {
        // Rule 12 — پیش‌تر به‌خاطر users.reseller_id تک‌مقداری در کانال‌ها ممکن نبود
        $product = $this->product(mainPrice: 12, resellerPrice: 10);
        $a = $this->reseller($product, 14);
        $c = $this->reseller($product, 16);

        $ali = User::factory()->create();
        $customers = app(\App\Services\Resellers\ResellerCustomerService::class);
        $customers->assign($a, $ali);
        $customers->assign($c, $ali);

        $aliInA = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($a));
        $aliInC = $this->identity->resolveCustomerAccount($ali, StoreContext::reseller($c));

        foreach ([$aliInA, $aliInC, $a, $c] as $owner) {
            $this->wallet->credit($owner, 100);
        }

        $accounts = app(\App\Services\Core\AccountService::class);
        $accounts->purchase($ali, $product, salesChannel: 'reseller_bot', reseller: $a, idempotencyKey: 'spec:r12:a');
        $accounts->purchase($ali, $product, salesChannel: 'reseller_bot', reseller: $c, idempotencyKey: 'spec:r12:c');

        $this->assertEquals(86, $this->balance($aliInA));
        $this->assertEquals(84, $this->balance($aliInC));
        $this->assertEquals(90, $this->balance($a));
        $this->assertEquals(90, $this->balance($c));
        $this->assertEquals(2, $ali->customerAccounts()->count());
    }

    #[Test]
    public function a_reseller_owner_buying_directly_from_main_pays_reseller_price_not_main_price(): void
    {
        // تصمیم بند ۱۸ ↔ Rule 2/13 (docs/history/PHASE-14-FINAL-MODEL-TESTS.md،
        // «یافته‌های نیازمند تصمیم»، مورد ۲): Context همچنان main
        // می‌ماند (Rule 13)، ولی مبلغ reseller_price است، نه main_price.
        $product = $this->product(mainPrice: 12, resellerPrice: 10);
        $reseller = $this->reseller($product, 14);

        $ownerMain = $this->identity->resolveCustomerAccount($reseller->user, StoreContext::main());
        $this->wallet->credit($ownerMain, 100);

        $this->purchase->purchase($ownerMain, $product, StoreContext::main(), idempotencyKey: 'spec:owner-pays-reseller-price');

        $order = Order::firstOrFail();

        $this->assertNull($order->reseller_id, 'خرید همچنان در Main Context ثبت می‌شود (Rule 13)');
        $this->assertEquals(10, (int) $order->main_price, 'مبلغ = reseller_price، نه main_price');
        $this->assertNull($order->customers_price);
        $this->assertEquals(90, $this->balance($ownerMain));

        // یک مشتری عادی (بدون نمایندگی) همچنان main_price می‌پردازد —
        // این تصمیم فقط برای صاحبِ نماینده است.
        $plainCustomer = $this->identity->resolveCustomerAccount(User::factory()->create(), StoreContext::main());
        $this->wallet->credit($plainCustomer, 100);

        $this->purchase->purchase($plainCustomer, $product, StoreContext::main(), idempotencyKey: 'spec:plain-customer-pays-main-price');

        $this->assertEquals(88, $this->balance($plainCustomer));
    }
}
