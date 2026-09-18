<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Category;
use App\Models\CustomerAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\AccountService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AccountService $accounts;

    protected WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(AccountService::class);
        $this->wallet = app(WalletService::class);
    }

    protected function makeCategoryWithPanel(): Category
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        return $category;
    }

    /**
     * از بند ۷ معماری: AccountService::purchase() برای خرید واقعی به
     * PurchaseService/PurchaseGuard جدید واگذار می‌کند که کیف‌پول را
     * روی CustomerAccountِ فروشگاه اصلی می‌بیند، نه روی خودِ User.
     */
    protected function mainWalletOwner(User $user): CustomerAccount
    {
        return app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
    }

    #[Test]
    public function purchase_fails_with_insufficient_balance_before_touching_the_panel(): void
    {
        Http::fake(); // هیچ درخواستی نباید ارسال شود

        $user = User::factory()->create();
        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 100000]);

        $this->expectException(InsufficientBalanceException::class);

        $this->accounts->purchase($user, $product);

        Http::assertNothingSent();
    }

    #[Test]
    public function successful_purchase_creates_account_and_deducts_wallet(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test', 'subscription_url' => 'https://sub.example.com/x'], 200),
        ]);

        $user = User::factory()->create();
        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 100000]);

        $this->wallet->charge($this->mainWalletOwner($user), 150000);

        $account = $this->accounts->purchase($user, $product);

        $this->assertEquals('active', $account->status);
        $this->assertEquals(50000, $this->wallet->balance($this->mainWalletOwner($user)));
        $this->assertNotEmpty($account->panel_username);
        $this->assertNotEmpty($account->panel_client_uuid);
        $this->assertDatabaseHas('orders', [
            'id' => $account->order_id,
            'status' => 'account_created',
        ]);
    }

    /**
     * طبق طراحی صریح PurchaseService/ProvisioningService جدید (مطابق
     * ProvisioningAndRenewalTest::a_panel_failure_after_payment_leaves_an_unambiguous_financial_state):
     * وقتی ساخت اکانت روی پنل شکست بخورد، پول به‌صورت خودکار بازگردانده
     * نمی‌شود — مالی از Provisioning جدا شده و سفارش صریحاً در وضعیت
     * provision_failed («پول گرفته شده، تحویل نشده») می‌ماند تا با
     * retryProvisioning() یا رسیدگی دستی ادمین جبران شود. نسخه‌ی قبلی
     * این تست انتظار بازگشت خودکار داشت که دقیقاً همان رفتار قدیمیِ
     * ناامنی بود که این معماری عمداً حذفش کرد.
     */
    #[Test]
    public function failed_panel_response_leaves_the_charge_and_marks_the_order_provision_failed(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['detail' => 'username already exists'], 409),
        ]);

        $user = User::factory()->create();
        $category = $this->makeCategoryWithPanel();
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 100000]);

        $this->wallet->charge($this->mainWalletOwner($user), 150000);

        $this->expectException(\RuntimeException::class);

        try {
            $this->accounts->purchase($user, $product);
        } finally {
            // موجودی همچنان کسرشده باقی می‌ماند — بازگشت خودکار نداریم.
            $this->assertEquals(50000, $this->wallet->balance($this->mainWalletOwner($user)));
            $this->assertEquals(
                Order::STATUS_PROVISION_FAILED,
                Order::query()->latest('id')->first()->status
            );
        }
    }

    #[Test]
    public function auto_selection_picks_the_panel_with_fewer_active_accounts(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test'], 200),
        ]);

        $busyPanel = ServerPanel::factory()->create(['active_accounts_count' => 50]);
        $freePanel = ServerPanel::factory()->create(['active_accounts_count' => 2]);

        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach([$busyPanel->id, $freePanel->id]);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 50000]);
        $user = User::factory()->create();
        $this->wallet->charge($this->mainWalletOwner($user), 50000);

        $account = $this->accounts->purchase($user, $product);

        $this->assertEquals($freePanel->id, $account->server_panel_id);
    }

    #[Test]
    public function random_naming_mode_uses_4_letter_server_prefix_volume_and_a_sequential_number(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'irrelevant'], 200),
        ]);

        $panel = ServerPanel::factory()->create(['name' => 'Germany Frankfurt', 'panel_type' => 'marzban']);
        // naming_mode پیش‌فرض DB برابر 'random' است — عمداً صریح ست نمی‌کنیم
        // تا همان مسیر پیش‌فرض واقعی تست شود.
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000, 'traffic_gb' => 30]);

        $user1 = User::factory()->create();
        $this->wallet->charge($this->mainWalletOwner($user1), 10000);
        $account1 = $this->accounts->purchase($user1, $product);

        $user2 = User::factory()->create();
        $this->wallet->charge($this->mainWalletOwner($user2), 10000);
        $account2 = $this->accounts->purchase($user2, $product);

        // ۴ حرف اول نام سرور (بدون فاصله): Germany Frankfurt → germ
        $this->assertEquals('germ_30_1', $account1->panel_username);
        $this->assertEquals('germ_30_1a', $account2->panel_username);
    }

    #[Test]
    public function custom_naming_mode_uses_the_given_name_and_appends_a_number_on_duplicate(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'irrelevant'], 200),
        ]);

        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto', 'naming_mode' => 'custom']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 10000]);

        $user1 = User::factory()->create();
        $this->wallet->charge($this->mainWalletOwner($user1), 10000);
        $account1 = $this->accounts->purchase($user1, $product, customUsername: 'ali');

        $this->assertEquals('ali', $account1->panel_username);

        // همان نام دوباره — چون تکراری است باید عدد ترتیبی بگیرد
        $user2 = User::factory()->create();
        $this->wallet->charge($this->mainWalletOwner($user2), 10000);
        $account2 = $this->accounts->purchase($user2, $product, customUsername: 'ali');

        $this->assertEquals('ali_1', $account2->panel_username);
    }

    #[Test]
    public function test_accounts_use_the_forced_username_regardless_of_the_category_naming_mode(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'irrelevant'], 200),
        ]);

        $panel = ServerPanel::factory()->create(['name' => 'Germany Frankfurt', 'panel_type' => 'marzban']);
        // naming_mode پیش‌فرض 'random' است — عمداً صریح ست نمی‌کنیم، چون
        // دقیقاً نکته‌ی این تست همین است: برای اکانت تست، حتی با
        // naming_mode='random' (که customUsername را نادیده می‌گیرد)،
        // نام باید همان customUsername باشد که MiscHandler::testAccount
        // بر اساس telegram_id ساخته و می‌فرستد — نه پیشوندِ سرور/حجم.
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 0, 'traffic_gb' => 30]);

        $user = User::factory()->create(['telegram_id' => 123456789]);

        $account = $this->accounts->purchase(
            $user,
            $product,
            salesChannel: 'test_account',
            customUsername: 'tg123456789',
            isTest: true,
            testTrafficMb: 500,
            testDurationHours: 1,
        );

        $this->assertEquals('tg123456789', $account->panel_username);
        $this->assertTrue($account->is_test);
    }
}
