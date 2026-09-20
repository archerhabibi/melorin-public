<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\PaymentService;
use App\Services\Resellers\ResellerPricingService;
use App\Services\Resellers\ResellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesTelegram;
use Tests\Concerns\StoreMembers;
use Tests\TestCase;

/**
 * رگرسیون‌های نسخه ۳.۰.۹ (موارد P1/P2 گزارش بازبینی).
 * این تست‌ها هرگز نباید حذف یا نرم شوند — هرکدام یک حفره‌ی تأییدشده را
 * قفل می‌کنند.
 */
class P1P2HardeningTest extends TestCase
{
    use FakesTelegram;
    use RefreshDatabase;
    use StoreMembers;

    protected function pendingPaymentFor(User $user, ?Reseller $reseller = null, string $walletOwnerType = 'user'): Payment
    {
        $method = PaymentMethod::factory()->create();

        ['payment' => $payment] = app(PaymentService::class)->initiate(
            $user, $method, 100000, 'wallet_charge', reseller: $reseller, walletOwnerType: $walletOwnerType
        );

        return $payment;
    }

    #[Test]
    public function a_user_cannot_attach_a_receipt_to_another_users_payment(): void
    {
        $victim = User::factory()->create(['telegram_id' => 111]);
        $attacker = User::factory()->create(['telegram_id' => 222]);

        $payment = $this->pendingPaymentFor($victim);

        // مهاجم با شناسه‌ی پرداختِ قربانی تلاش می‌کند
        $found = Payment::findPendingForReceipt($payment->id, $attacker, null, 'user');

        $this->assertNull($found, 'پرداخت کاربر دیگر نباید برای این کاربر قابل‌واکشی باشد.');
    }

    #[Test]
    public function the_rightful_owner_can_still_attach_a_receipt(): void
    {
        $user = User::factory()->create(['telegram_id' => 333]);
        $payment = $this->pendingPaymentFor($user);

        $found = Payment::findPendingForReceipt($payment->id, $user, null, 'user');

        $this->assertNotNull($found, 'مالک واقعی باید بتواند رسید خود را ثبت کند (ضدآزمون).');
        $this->assertEquals($payment->id, $found->id);
    }

    #[Test]
    public function an_already_confirmed_payment_cannot_be_reopened_for_a_receipt(): void
    {
        $this->fakeTelegram();
        $user = User::factory()->create(['telegram_id' => 444]);
        $payment = $this->pendingPaymentFor($user);

        app(PaymentService::class)->confirmManual($payment, Admin::factory()->create());

        $this->assertNull(Payment::findPendingForReceipt($payment->id, $user, null, 'user'));
    }

    #[Test]
    public function approving_a_payment_writes_an_audit_log_entry(): void
    {
        $this->fakeTelegram();
        $user = User::factory()->create(['telegram_id' => 555]);
        $payment = $this->pendingPaymentFor($user);

        app(PaymentService::class)->confirmManual($payment, Admin::factory()->create());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.approved',
            'target_id' => $payment->id,
        ]);
    }

    #[Test]
    public function reseller_lifecycle_changes_are_audited(): void
    {
        $owner = User::factory()->create();
        $service = app(ResellerService::class);

        $reseller = $service->create($owner, ['bot_token' => 'x', 'slug' => 'auditshop']);
        $service->deactivate($reseller);

        $this->assertDatabaseHas('audit_logs', ['action' => 'reseller.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reseller.deactivated']);
    }

    #[Test]
    public function a_reseller_cannot_enable_a_category_the_main_admin_closed_globally(): void
    {
        $reseller = Reseller::factory()->create();
        $category = Category::factory()->create(['available_to_resellers' => false]);

        // پیش از این، تنها مانع، پنهان‌بودن سبد در UI بود — نه خودِ سرویس.
        $this->expectException(InvalidArgumentException::class);

        app(ResellerPricingService::class)->setCategoryEnabled($reseller, $category, true);
    }

    #[Test]
    public function a_reseller_can_still_enable_a_globally_allowed_category(): void
    {
        $reseller = Reseller::factory()->create();
        $category = Category::factory()->create(['available_to_resellers' => true]);

        $setting = app(ResellerPricingService::class)->setCategoryEnabled($reseller, $category, true);

        $this->assertTrue((bool) $setting->is_enabled, 'ضدآزمون: مسیر سالم نباید شکسته باشد.');
    }

    #[Test]
    public function changing_the_bot_token_triggers_a_webhook_re_registration(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'description' => 'ok'], 200)]);

        $reseller = Reseller::factory()->create(['bot_token' => 'old-token']);
        $service = app(ResellerService::class);

        $reseller->update(['bot_token' => 'new-token']);
        $result = $service->syncWebhookIfTokenChanged($reseller->fresh(), 'old-token');

        $this->assertTrue($result['success']);
        $this->assertEquals('ok', $reseller->fresh()->webhook_status);
    }

    #[Test]
    public function an_unchanged_bot_token_does_not_call_telegram_again(): void
    {
        Http::fake();

        $reseller = Reseller::factory()->create(['bot_token' => 'same-token']);

        app(ResellerService::class)->syncWebhookIfTokenChanged($reseller, 'same-token');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_failed_webhook_registration_is_recorded_on_the_reseller(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $reseller = Reseller::factory()->create(['bot_token' => 'bad-token']);

        app(ResellerService::class)->registerWebhook($reseller);

        $fresh = $reseller->fresh();
        $this->assertEquals('failed', $fresh->webhook_status);
        $this->assertStringContainsString('Unauthorized', $fresh->webhook_error);
    }

    #[Test]
    public function a_broadcast_records_one_recipient_row_per_targeted_user(): void
    {
        // مخاطب Main = عضو فعال Main با telegram_id (Rule 12: عضویت CustomerAccount است)
        $this->accountIn(User::factory()->create(['telegram_id' => 901]), null);
        $this->accountIn(User::factory()->create(['telegram_id' => 902]), null);
        $this->accountIn(User::factory()->create(['telegram_id' => null]), null); // بدون تلگرام — نباید هدف باشد
        // مشتریِ صرفاً یک نماینده، مخاطب پیام همگانی Main نیست
        $this->memberOf(Reseller::factory()->create(), ['telegram_id' => 903]);

        $broadcast = app(\App\Services\Core\BroadcastService::class)->create('سلام', null);

        $this->assertEquals(2, $broadcast->total_recipients);
        $this->assertDatabaseCount('broadcast_recipients', 2);
        $this->assertEquals('queued', $broadcast->status);
    }

    #[Test]
    public function a_reseller_broadcast_only_targets_that_resellers_customers(): void
    {
        $resellerA = Reseller::factory()->create();
        $resellerB = Reseller::factory()->create();

        $mine = $this->memberOf($resellerA, ['telegram_id' => 1001]);
        $this->memberOf($resellerB, ['telegram_id' => 2002]);
        $this->accountIn(User::factory()->create(['telegram_id' => 3003]), null); // مشتری مستقیم Main

        // مشتریِ هر دو نماینده (Rule 12) پیام هر فروشگاه را جدا می‌گیرد
        $both = $this->memberOf($resellerA, ['telegram_id' => 4004]);
        $this->accountIn($both, $resellerB);

        $service = app(\App\Services\Core\BroadcastService::class);

        $broadcastA = $service->create('سلام', $resellerA);
        $broadcastB = $service->create('سلام', $resellerB);

        $this->assertEquals(2, $broadcastA->total_recipients);
        $this->assertDatabaseHas('broadcast_recipients', ['broadcast_id' => $broadcastA->id, 'user_id' => $mine->id]);
        $this->assertDatabaseHas('broadcast_recipients', ['broadcast_id' => $broadcastA->id, 'user_id' => $both->id]);

        $this->assertEquals(2, $broadcastB->total_recipients);
        $this->assertDatabaseHas('broadcast_recipients', ['broadcast_id' => $broadcastB->id, 'user_id' => $both->id]);
    }
}
