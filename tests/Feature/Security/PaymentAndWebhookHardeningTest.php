<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerAdmin;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Core\WalletService;
use App\Services\Resellers\ResellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesTelegram;
use Tests\TestCase;

/**
 * تست‌های رگرسیونِ P0 — بخش دوم: پرداخت، وب‌هوک، و قفلِ نماینده‌ی
 * غیرفعال. ر.ک. توضیح ابتدای ResellerSellabilityGuardTest.
 */
class PaymentAndWebhookHardeningTest extends TestCase
{
    use RefreshDatabase, FakesTelegram;

    protected function pendingWalletCharge(User $user, float $amount = 100000): Payment
    {
        $method = PaymentMethod::factory()->create(['type' => 'card_to_card', 'status' => 'active']);

        return Payment::create([
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
            'wallet_owner_type' => 'user',
        ]);
    }

    /**
     * Critical #6 — تأیید دوباره‌ی یک پرداختِ از قبل تأییدشده باید رد
     * شود، نه اینکه کیف پول را دو بار شارژ کند. (شبیه‌سازی مستقیمِ
     * حالتی که در عمل با دو کلیک هم‌زمان ادمین رخ می‌دهد.)
     */
    /** @test */
    public function confirming_an_already_confirmed_payment_does_not_double_credit_the_wallet(): void
    {
        $this->fakeTelegram();
        $admin = Admin::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();
        $payment = $this->pendingWalletCharge($user, 100000);

        $service = app(PaymentService::class);
        $wallet = app(WalletService::class);

        $service->confirmManual($payment, $admin);
        $this->assertEquals(100000, $wallet->balance($user->fresh()));

        try {
            $service->confirmManual($payment->fresh(), $admin);
        } catch (\Throwable) {
            // رد شدن، رفتار درست است
        }

        // مهم‌ترین assert این فایل: موجودی نباید ۲۰۰٬۰۰۰ شده باشد.
        $this->assertEquals(100000, $wallet->balance($user->fresh()));
    }

    /** Critical #5 — وب‌هوک نماینده بدون secret درست باید ۴۰۳ بدهد. */
    /** @test */
    public function a_reseller_webhook_request_without_the_correct_secret_is_rejected(): void
    {
        $owner = User::factory()->create();
        $reseller = app(ResellerService::class)->create($owner, [
            'bot_token' => 'test-token',
            'webhook_slug' => 'test-shop',
            'slug' => 'test-shop',
        ]);
        $reseller->ensureWebhookSecret();

        $payload = ['update_id' => 1, 'message' => [
            'message_id' => 1,
            'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'X'],
            'chat' => ['id' => 555, 'type' => 'private'],
            'text' => '/start',
        ]];

        // بدون هدر
        $this->postJson('/reseller-bot/webhook/test-shop', $payload)->assertForbidden();

        // با هدر اشتباه
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
            ->postJson('/reseller-bot/webhook/test-shop', $payload)
            ->assertForbidden();
    }

    /** @test */
    public function a_reseller_webhook_request_with_the_correct_secret_is_accepted(): void
    {

    $telegram = $this->fakeTelegram();

    $telegram->shouldReceive('getMe')
    ->once()
    ->andReturn(new \Telegram\Bot\Objects\User([
        'id' => 999999,
        'is_bot' => true,
        'first_name' => 'Test Bot',
        'username' => 'test_bot',
    ]));

    $this->mock(\App\Channels\ResellerBot\ResellerApiFactory::class, function ($mock) use ($telegram) {
        $mock->shouldReceive('make')
            ->once()
            ->andReturn($telegram);
    });

    $owner = User::factory()->create();

    $reseller = app(ResellerService::class)->create($owner, [
        'bot_token' => 'test-token',
        'webhook_slug' => 'test-shop-2',
        'slug' => 'test-shop-2',
    ]);

    $secret = $reseller->ensureWebhookSecret();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)
        ->postJson('/reseller-bot/webhook/test-shop-2', [
            'update_id' => 2,
            'message' => [
                'message_id' => 1,
                'from' => [
                    'id' => 556,
                    'is_bot' => false,
                    'first_name' => 'X',
                ],
                'chat' => [
                    'id' => 556,
                    'type' => 'private',
                ],
                'text' => '/start',
            ],
        ])
        ->assertOk();
    }

    /** Critical #4 — نماینده‌ی غیرفعال نباید بتواند وارد پنل شود. */
    /** @test */
    public function an_inactive_reseller_owner_cannot_access_the_reseller_panel(): void
    {
        $owner = User::factory()->create();
        $reseller = app(ResellerService::class)->create($owner, ['slug' => 'shop-x']);

        $panel = \Filament\Facades\Filament::getPanel('reseller');

        $this->assertTrue($owner->fresh()->canAccessPanel($panel));

        $reseller->update(['status' => 'inactive']);

        $this->assertFalse($owner->fresh()->canAccessPanel($panel));
        $this->assertFalse($owner->fresh()->canAccessTenant($reseller->fresh()));
        $this->assertCount(0, $owner->fresh()->getTenants($panel));
    }

    /** P1 #13 — ساخت نماینده باید اتمیک باشد. */
    /** @test */
    public function reseller_creation_is_atomic(): void
    {
        $owner = User::factory()->create();
        $reseller = app(ResellerService::class)->create($owner, ['slug' => 'atomic-shop']);

        $this->assertDatabaseHas('resellers', ['id' => $reseller->id]);
        $this->assertDatabaseHas('reseller_admins', [
            'reseller_id' => $reseller->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        // هیچ نماینده‌ای نباید بدون مالک وجود داشته باشد.
        $this->assertEquals(
            0,
            Reseller::query()->whereDoesntHave('admins')->count(),
            'یک Reseller بدون هیچ ResellerAdmin پیدا شد — ساخت اتمیک نیست.'
        );
    }
}
