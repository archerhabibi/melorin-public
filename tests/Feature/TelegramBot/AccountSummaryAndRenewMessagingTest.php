<?php

namespace Tests\Feature\TelegramBot;

use App\Channels\TelegramBot\Handlers\AccountsHandler;
use App\Channels\TelegramBot\Handlers\StartHandler;
use App\Models\Account;
use App\Models\AffiliateSetting;
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
use Telegram\Bot\Objects\User as TelegramUser;
use Tests\TestCase;

/**
 * پوشش تست برای سه رفع/فیچر درخواستی که پیام واقعی ارسالی به تلگرام را
 * باید بررسی کند (پس نمی‌تواند از FakesTelegram::shouldIgnoreMissing()
 * استفاده کند — دقیقاً همان الگوی دستی که RenewAccountFlowTest دارد):
 *
 * ۱. خلاصه‌ی «استعلام اکانت» باید نام اکانت و حجم باقی‌مانده را نشان دهد.
 * ۲. پیام موفقیتِ تمدید باید موجودی قبل و بعد از تمدید را نشان دهد.
 * ۳. بعد از پرداخت پاداش دعوت، خودِ معرف هم یک پیام اطلاع‌رسانی بگیرد.
 */
class AccountSummaryAndRenewMessagingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{chat_id: mixed, text: string}> */
    protected array $sentMessages = [];

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

        // StartHandler::botDisplayName() برای پیام خوش‌آمدگویی از getMe()
        // استفاده می‌کند — این تست دستی/strict است (نه FakesTelegram)
        // پس بدون این استاب هر فراخوانی getMe() یک BadMethodCallException
        // می‌دهد، حتی وقتی خودِ پیام خوش‌آمدگویی مورد بررسی این تست نیست.
        $telegram->shouldReceive('getMe')
            ->zeroOrMoreTimes()
            ->andReturn(new TelegramUser([
                'id' => 1,
                'is_bot' => true,
                'first_name' => 'Melorin',
                'username' => 'MelorinBot',
            ]));

        $this->app->instance(Api::class, $telegram);
    }

    protected function makeAccountWithTraffic(User $user, float $price, float $trafficGb, float $trafficUsedGb): Account
    {
        $panel = ServerPanel::factory()->create(['panel_type' => 'marzban']);
        $category = Category::factory()->create();
        $category->serverPanels()->attach($panel);

        $product = Product::factory()->create(['category_id' => $category->id, 'price' => $price]);

        return Account::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'server_panel_id' => $panel->id,
            'panel_username' => 'melorin_summary_test',
            'status' => 'active',
            'expires_at' => now()->addDays(10),
            'traffic_gb' => $trafficGb,
            'traffic_used_gb' => $trafficUsedGb,
        ]);
    }

    #[Test]
    public function inquiry_summary_shows_account_name_and_remaining_traffic(): void
    {
        Http::fake();

        $user = User::factory()->create(['telegram_id' => 700]);
        $this->makeAccountWithTraffic($user, 100000, trafficGb: 50, trafficUsedGb: 20);

        app(AccountsHandler::class)->list(700, $user);

        $summary = collect($this->sentMessages)->firstWhere(fn ($m) => str_contains($m['text'], 'melorin_summary_test'));

        $this->assertNotNull($summary, 'خلاصه‌ی اکانت ارسال نشد.');
        $this->assertStringContainsString('نام اکانت: melorin_summary_test', $summary['text']);
        $this->assertStringContainsString('حجم کل: 50.00 گیگ', $summary['text']);
        $this->assertStringContainsString('حجم باقی‌مانده: 30.00 گیگ', $summary['text']);
    }

    #[Test]
    public function renew_success_message_shows_balance_before_and_after(): void
    {
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/api/user/*' => Http::response(['username' => 'melorin_summary_test'], 200),
        ]);

        $user = User::factory()->create(['telegram_id' => 701]);
        $account = $this->makeAccountWithTraffic($user, 100000, trafficGb: 50, trafficUsedGb: 0);

        app(WalletService::class)->charge($user, 250000);

        app(AccountsHandler::class)->renew(701, $user, $account->id);

        $success = collect($this->sentMessages)->firstWhere(fn ($m) => str_contains($m['text'], 'با موفقیت تمدید شد'));

        $this->assertNotNull($success, 'پیام موفقیت تمدید ارسال نشد.');
        $this->assertStringContainsString('موجودی کیف پول قبل از تمدید: 250,000 تومان', $success['text']);
        $this->assertStringContainsString('موجودی کیف پول بعد از تمدید: 150,000 تومان', $success['text']);
    }

    #[Test]
    public function referrer_receives_a_notification_message_after_being_credited(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 30000]);

        $referrer = User::factory()->create(['telegram_id' => 800]);

        $newUser = User::factory()->make(['telegram_id' => 900, 'referrer_id' => null]);
        $newUser->save();

        app(StartHandler::class)->handle(900, $newUser, (string) $referrer->id);

        $notification = collect($this->sentMessages)
            ->firstWhere(fn ($m) => $m['chat_id'] == 800 && str_contains($m['text'], 'عضو ربات شد'));

        $this->assertNotNull($notification, 'پیام اطلاع‌رسانی به معرف ارسال نشد.');
        $this->assertStringContainsString('30,000 تومان', $notification['text']);
        $this->assertStringContainsString('موجودی فعلی: 30,000 تومان', $notification['text']);
    }

    #[Test]
    public function no_notification_is_sent_when_referrer_has_no_telegram_id(): void
    {
        Http::fake();

        AffiliateSetting::current()->update(['referrer_bonus_amount' => 30000]);

        // معرفی که فقط از سایت ثبت‌نام کرده و telegram_id ندارد.
        $referrer = User::factory()->create(['telegram_id' => null, 'username_site' => 'siteuser1']);

        $newUser = User::factory()->make(['telegram_id' => 901, 'referrer_id' => null]);
        $newUser->save();

        app(StartHandler::class)->handle(901, $newUser, (string) $referrer->id);

        // پاداش باید در هر صورت پرداخت شده باشد؛ فقط پیامی برای ارسال
        // نیست چون telegram_id ندارد.
        $this->assertEquals(30000, app(WalletService::class)->balance($referrer->fresh()));

        $notification = collect($this->sentMessages)->firstWhere(fn ($m) => str_contains($m['text'], 'عضو ربات شد'));
        $this->assertNull($notification);
    }
}
