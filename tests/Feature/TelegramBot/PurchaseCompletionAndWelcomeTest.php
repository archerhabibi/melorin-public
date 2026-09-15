<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\BuyAccountHandler;
use App\Channels\TelegramBot\Handlers\StartHandler;
use App\Models\Category;
use App\Models\Product;
use App\Models\ServerPanel;
use App\Models\User;
use App\Services\Core\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\User as TelegramUser;
use Tests\TestCase;

class PurchaseCompletionAndWelcomeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{chat_id: mixed, text: string}> */
    protected array $sentMessages = [];

    protected Mockery\MockInterface $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentMessages = [];

        $telegram = Mockery::mock(Api::class);

        $telegram->shouldReceive('sendMessage')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (array $params) {
                $this->sentMessages[] = ['chat_id' => $params['chat_id'] ?? null, 'text' => $params['text'] ?? ''];

                return new Message([
                    'message_id' => count($this->sentMessages),
                    'date' => time(),
                    'chat' => ['id' => $params['chat_id'] ?? 555, 'type' => 'private'],
                    'text' => $params['text'] ?? '',
                ]);
            });

        $telegram->shouldReceive('sendPhoto')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (array $params) => new Message([
                'message_id' => 999,
                'date' => time(),
                'chat' => ['id' => $params['chat_id'] ?? 555, 'type' => 'private'],
            ]));

        $this->telegram = $telegram;
        $this->app->instance(Api::class, $telegram);
    }

    #[Test]
    public function wallet_balance_is_shown_right_after_a_purchase_completes(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user' => Http::response(['username' => 'melorin_test', 'subscription_url' => 'https://sub.example.com/x'], 200),
        ]);

        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create(['server_selection_mode' => 'auto']);
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 150000]);

        $user = User::factory()->create(['telegram_id' => 111222333]);
        app(WalletService::class)->charge($user, 200000);

        app(BuyAccountHandler::class)->purchase(111222333, $user, $product->id);

        $balanceMessage = collect($this->sentMessages)->firstWhere(fn ($m) => str_contains($m['text'], 'موجودی کیف پول شما'));

        $this->assertNotNull($balanceMessage, 'پیام موجودی کیف پول بعد از خرید ارسال نشد.');
        $this->assertStringContainsString('50,000 تومان', $balanceMessage['text']);
    }

    #[Test]
    public function welcome_message_uses_the_bots_actual_telegram_name(): void
    {
        Cache::forget('telegram_bot_display_name');

        $this->telegram->shouldReceive('getMe')
            ->once() // کش شده — نباید برای هر /start دوباره صدا زده شود
            ->andReturn(new TelegramUser(['id' => 1, 'is_bot' => true, 'first_name' => 'Melorin VPN Support', 'username' => 'MelorinBot']));

        $user = User::factory()->create(['telegram_id' => 444555666]);

        app(StartHandler::class)->handle(444555666, $user, null);
        app(StartHandler::class)->handle(444555666, $user, null); // بار دوم — باید از کش بیاید

        $welcome = collect($this->sentMessages)->firstWhere(fn ($m) => str_contains($m['text'], 'خوش آمدید'));

        $this->assertNotNull($welcome);
        $this->assertStringContainsString('Melorin VPN Support', $welcome['text']);
    }
}
