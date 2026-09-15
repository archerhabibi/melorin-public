<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\BuyAccountHandler;
use App\Models\Category;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class BuyAccountFlowTest extends TestCase
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
                        'id' => $params['chat_id'] ?? 111222333,
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
                        'id' => $params['chat_id'] ?? 111222333,
                        'type' => 'private',
                    ],
                ]);
            });

        $this->app->instance(Api::class, $telegram);
    }

    protected function makeCategoryWithPanel(): Category
    {
        $panel = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
        ]);

        $category = Category::factory()->create([
            'server_selection_mode' => 'auto',
        ]);

        $category->serverPanels()->attach($panel);

        return $category;
    }

    #[Test]
    public function purchase_with_insufficient_wallet_balance_never_calls_the_panel(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'telegram_id' => 111222333,
        ]);

        $category = $this->makeCategoryWithPanel();

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 150000,
        ]);

        app(BuyAccountHandler::class)->purchase(
            111222333,
            $user,
            $product->id
        );

        Http::assertNothingSent();

        $this->assertDatabaseMissing('accounts', [
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function purchase_with_sufficient_balance_creates_account_and_delivers_config(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'fake-token',
            ], 200),

            '*/api/user' => Http::response([
                'username' => 'melorin_test',
                'subscription_url' => 'https://sub.example.com/x',
            ], 200),

            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'telegram_id' => 111222333,
        ]);

        $category = $this->makeCategoryWithPanel();

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 150000,
        ]);

        app(WalletService::class)->charge($user, 200000);

        app(BuyAccountHandler::class)->purchase(
            111222333,
            $user,
            $product->id
        );

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertEquals(
            50000,
            app(WalletService::class)->balance($user->fresh())
        );
    }

    #[Test]
    public function manual_server_selection_category_shows_server_list_instead_of_auto_purchasing(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'telegram_id' => 111222333,
        ]);

        $panelX = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
            'name' => 'X',
        ]);

        $panelY = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
            'name' => 'Y',
        ]);

        $category = Category::factory()->create([
            'server_selection_mode' => 'manual',
        ]);

        $category->serverPanels()->attach([
            $panelX->id,
            $panelY->id,
        ]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 150000,
        ]);

        app(WalletService::class)->charge($user, 200000);

        app(BuyAccountHandler::class)->chooseServerOrPurchase(
            111222333,
            $user,
            $product->id
        );

        $this->assertDatabaseMissing('accounts', [
            'user_id' => $user->id,
        ]);

        $this->assertEquals(
            200000,
            app(WalletService::class)->balance($user->fresh())
        );
    }

    #[Test]
    public function purchasing_with_a_chosen_panel_id_uses_exactly_that_server(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response([
                'access_token' => 'fake-token',
            ], 200),

            '*/api/user' => Http::response([
                'username' => 'melorin_test',
                'subscription_url' => 'https://sub.example.com/x',
            ], 200),

            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 1,
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'telegram_id' => 111222333,
        ]);

        $panelX = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
            'name' => 'X',
        ]);

        $panelY = ServerPanel::factory()->create([
            'panel_type' => 'marzban',
            'name' => 'Y',
        ]);

        $category = Category::factory()->create([
            'server_selection_mode' => 'manual',
        ]);

        $category->serverPanels()->attach([
            $panelX->id,
            $panelY->id,
        ]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'price' => 150000,
        ]);

        app(WalletService::class)->charge($user, 200000);

        app(BuyAccountHandler::class)->purchase(
            111222333,
            $user,
            $product->id,
            $panelY->id
        );

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'server_panel_id' => $panelY->id,
        ]);
    }
}
