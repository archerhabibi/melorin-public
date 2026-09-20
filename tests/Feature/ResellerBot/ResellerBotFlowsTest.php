<?php

namespace Tests\Feature\ResellerBot;

use App\Channels\ResellerBot\Support\ConversationState;
use App\Channels\ResellerBot\UpdateRouter;
use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\ResellerProductPrice;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\WalletService;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;
use Telegram\Bot\Objects\User as TelegramUser;
use Tests\TestCase;

/**
 * این تست‌ها مستقیماً UpdateRouter را صدا می‌زنند (نه از طریق HTTP)،
 * دقیقاً هم‌الگو با tests/Feature/TelegramBot/BuyAccountFlowTest ربات
 * اصلی — تمرکز اینجا روی رفتار Handlerها/مسیریابی است، نه چندمستأجریِ
 * وب‌هوک (که در WebhookMultiTenancyTest پوشش داده شده).
 */
class ResellerBotFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected Api $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (array $params) => new Message([
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'],
                'text' => $params['text'] ?? '',
            ]));

        $telegram->shouldReceive('sendPhoto')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (array $params) => new Message([
                'message_id' => 2,
                'date' => time(),
                'chat' => ['id' => $params['chat_id'] ?? 1, 'type' => 'private'],
            ]));

        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
        $telegram->shouldReceive('getMe')->zeroOrMoreTimes()->andReturn(
            new TelegramUser(['id' => 1, 'is_bot' => true, 'first_name' => 'Shop', 'username' => 'shop_bot'])
        );

        $this->app->instance(Api::class, $telegram);
        $this->telegram = $telegram;
    }

    protected function router(): UpdateRouter
    {
        return app(UpdateRouter::class);
    }

    protected function textUpdate(int $chatId, string $text): Update
    {
        return new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chatId, 'type' => 'private'], 'text' => $text],
        ]);
    }

    protected function callbackUpdate(int $chatId, string $data): Update
    {
        return new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'callback_query' => [
                'id' => '1',
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'T'],
                'message' => ['message_id' => 1, 'date' => time(), 'chat' => ['id' => $chatId, 'type' => 'private']],
                'data' => $data,
            ],
        ]);
    }

    protected function sellableProduct(Reseller $reseller, float $mainPrice, float $customersPrice): Product
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['status' => 'active', 'server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'main_price' => $mainPrice, 'status' => 'active']);

        ResellerProductPrice::create([
            'reseller_id' => $reseller->id,
            'product_id' => $product->id,
            'customers_price' => $customersPrice,
            'is_enabled' => true,
        ]);

        return $product;
    }

    #[Test]
    public function a_customer_can_buy_an_account_end_to_end_through_the_bot_with_double_debit(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test', 'subscription_url' => 'https://sub.example.com/x'], 200),
        ]);

        $reseller = Reseller::factory()->create();
        $customerUser = User::factory()->create([
            'telegram_id' => 900001,
        ]);

        $customerAccount = app(\App\Services\Core\Store\IdentityService::class)
            ->resolveCustomerAccount(
                $customerUser,
                \App\Services\Core\Store\StoreContext::fromReseller($reseller),
            );
        $product = $this->sellableProduct($reseller, mainPrice: 10, customersPrice: 14);

        $wallet = app(WalletService::class);
        $wallet->charge($customerAccount, 20);
        $wallet->charge($reseller, 30);

        $chatId = 900001;

        $this->router()->handle($reseller, $this->textUpdate($chatId, '🛒 خرید اکانت'), $customerUser, $chatId);
        $this->router()->handle($reseller, $this->callbackUpdate($chatId, "rbuy:category:{$product->category_id}"), $customerUser, $chatId);
        $this->router()->handle($reseller, $this->callbackUpdate($chatId, "rbuy:product:{$product->id}"), $customerUser, $chatId);

        $this->assertEquals(6, $wallet->balance($customerAccount));
        $this->assertEquals(20, $wallet->balance($reseller));
        $this->assertDatabaseHas('orders', [
            'reseller_id' => $reseller->id,
            'sales_channel' => 'reseller_bot',
            'reseller_price' => 10,
            'customers_price' => 14,
        ]);
    }

    #[Test]
    public function owner_main_wallet_topup_menu_item_is_a_no_op_for_a_plain_customer(): void
    {
        $reseller = Reseller::factory()->create();
        $customer = User::factory()->create(['reseller_id' => $reseller->id, 'telegram_id' => 900002]);

        $this->router()->handle($reseller, $this->textUpdate(900002, '💰 شارژ حساب نماینده'), $customer, 900002);

        // چون owner نیست، هیچ حالتِ مکالمه‌ای برای شارژ نماینده نباید ساخته شده باشد
        $this->assertDatabaseMissing('reseller_conversation_states', [
            'reseller_id' => $reseller->id,
            'telegram_chat_id' => 900002,
            'step' => ConversationState::OWNER_MAIN_WALLET_TOPUP_CHOOSE_METHOD,
        ]);
    }

    #[Test]
    public function customers_topup_is_reviewed_by_the_reseller_and_credits_only_the_customers_reseller_context_wallet(): void
    {
        $ownerUser = User::factory()->create(['telegram_id' => 800001]);
        $reseller = Reseller::factory()->create(['user_id' => $ownerUser->id]);
        ResellerAdmin::query()->create([
            'reseller_id' => $reseller->id,
            'user_id' => $ownerUser->id,
            'role' => 'owner',
        ]);

        $customerUser = User::factory()->create([
            'telegram_id' => 800002,
        ]);

        $customerAccount = app(IdentityService::class)
            ->resolveCustomerAccount(
                $customerUser,
                StoreContext::fromReseller($reseller),
            );

        PaymentMethod::factory()->create(['status' => 'active']);
        $method = PaymentMethod::query()->first();

        $chatId = 800002;

        $this->router()->handle($reseller, $this->textUpdate($chatId, '💰 شارژ حساب'), $customerUser, $chatId);
        $this->router()->handle($reseller, $this->callbackUpdate($chatId, 'rwallet:amount:200000'), $customerUser, $chatId);
        $this->router()->handle($reseller, $this->callbackUpdate($chatId, "rwallet:method:{$method->id}"), $customerUser, $chatId);

        $photoUpdate = new Update([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'photo' => [['file_id' => 'FILE123', 'width' => 100, 'height' => 100]],
            ],
        ]);
        $this->router()->handle($reseller, $photoUpdate, $customerUser, $chatId);
        $this->router()->handle($reseller, $this->textUpdate($chatId, 'علی رضایی'), $customerUser, $chatId);

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertEquals('user', $payment->wallet_owner_type);
        $this->assertEquals($reseller->id, $payment->reseller_id);
        $this->assertEquals('pending', $payment->status);

        // حالا owner از طریق ربات همان دکمه‌ی تاییدِ این‌لاین را می‌زند
        $this->router()->handle($reseller, $this->callbackUpdate(800001, "rpay:approve:{$payment->id}"), $ownerUser, 800001);

        $wallet = app(WalletService::class);
        $this->assertEquals(200000, $wallet->balance($customerAccount));
        $this->assertEquals(0, $wallet->balance($reseller));
    }
}
