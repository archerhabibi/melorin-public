<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\AccountsHandler;
use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class RenewAccountFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                return new Message([
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => [
                        'id' => $params['chat_id'] ?? 555,
                        'type' => 'private',
                    ],
                    'text' => $params['text'] ?? '',
                ]);
            });

        $telegram->shouldReceive('sendPhoto')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                return new Message([
                    'message_id' => 2,
                    'date' => time(),
                    'chat' => [
                        'id' => $params['chat_id'] ?? 555,
                        'type' => 'private',
                    ],
                ]);
            });

        $this->app->instance(Api::class, $telegram);
    }

    protected function makeAccount(User $user, float $price = 100000): Account
    {
        $panel = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
        ]);

        $category = Category::factory()->create();

        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'main_price' => $price,
        ]);

        $customerAccount = app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());

        return Account::factory()->create([
            'user_id' => $user->id,
            'customer_account_id' => $customerAccount->id,
            'product_id' => $product->id,
            'server_panel_id' => $panel->id,
            'panel_username' => 'melorin_existing',
            'status' => 'active',
            'expires_at' => now()->addDays(5),
        ]);
    }

    /**
     * از بند ۷ معماری: کیف‌پولی که AccountsHandler::renew() واقعاً از آن
     * کسر می‌کند (از طریق RenewalService)، کیف‌پولِ CustomerAccountِ
     * فروشگاه اصلیِ این کاربر است، نه کیف‌پولی که مستقیماً روی خودِ
     * User باشد.
     */
    protected function mainWalletOwner(User $user): \App\Models\CustomerAccount
    {
        return app(IdentityService::class)->resolveCustomerAccount($user, StoreContext::main());
    }

    #[Test]
    public function successful_renewal_deducts_wallet_and_extends_expiry(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'fake-token',
            ], 200),

            '*/api/user/*' => Http::response([
                'username' => 'melorin_existing',
            ], 200),

            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'telegram_id' => 555,
        ]);

        $account = $this->makeAccount($user, 100000);

        app(WalletService::class)->charge($this->mainWalletOwner($user), 150000);

        app(AccountsHandler::class)->renew(
            555,
            $user,
            $account->id
        );

        $this->assertEquals(
            50000,
            app(WalletService::class)->balance($this->mainWalletOwner($user))
        );

        $this->assertTrue(
            $account->fresh()->expires_at->isAfter(now()->addDays(30))
        );
    }

    /**
     * طبق طراحی صریح RenewalService (مطابق
     * ProvisioningAndRenewalTest::a_panel_failure_during_renewal_is_recorded_not_silently_swallowed):
     * اگر تمدید روی پنل شکست بخورد، پول به‌صورت خودکار بازگردانده
     * نمی‌شود — چون ممکن است تمدید واقعاً روی پنل انجام شده باشد و فقط
     * پاسخش نرسیده باشد؛ بازگشتِ خودکار در آن حالت یعنی هم سرویس داده‌ایم
     * هم پول را پس داده‌ایم. به‌جایش سفارش صریحاً در وضعیت
     * provision_failed («پول گرفته شده، تحویل نشده») می‌ماند تا ادمین
     * تصمیم بگیرد. نسخه‌ی قبلی این تست انتظار بازگشت خودکار داشت که
     * دقیقاً همان رفتار قدیمیِ ناامنی بود که RenewalService عمداً حذفش
     * کرد.
     */
    #[Test]
    public function failed_panel_renewal_leaves_the_charge_and_marks_the_order_provision_failed(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'fake-token',
            ], 200),

            '*/api/user/*' => Http::response([
                'detail' => 'panel unreachable',
            ], 500),

            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'telegram_id' => 556,
        ]);

        $account = $this->makeAccount($user, 100000);

        app(WalletService::class)->charge($this->mainWalletOwner($user), 150000);

        app(AccountsHandler::class)->renew(
            556,
            $user,
            $account->id
        );

        // موجودی همچنان کسرشده باقی می‌ماند — بازگشت خودکار نداریم.
        $this->assertEquals(
            50000,
            app(WalletService::class)->balance($this->mainWalletOwner($user))
        );

        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'purchase',
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'type' => 'refund',
        ]);

        $this->assertEquals(
            Order::STATUS_PROVISION_FAILED,
            Order::query()->latest('id')->first()->status
        );
    }

    #[Test]
    public function insufficient_balance_never_touches_the_panel(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'telegram_id' => 557,
        ]);

        $account = $this->makeAccount($user, 100000);

        app(WalletService::class)->charge($this->mainWalletOwner($user), 50000);

        app(AccountsHandler::class)->renew(
            557,
            $user,
            $account->id
        );

        Http::assertNothingSent();

        $this->assertEquals(
            50000,
            app(WalletService::class)->balance($this->mainWalletOwner($user))
        );
    }
}