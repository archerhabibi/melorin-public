<?php

namespace Tests\Feature\Website;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پچ 3.2.2 — فاز W2 (ادامه): شارژ کیف‌پول از سایت با Zarinpal و
 * Card-to-Card. مرجع: docs/PHASE-W2-PART2-PAYMENT-METHODS.md
 */
class WalletChargeFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function choosing_zarinpal_redirects_the_user_to_the_gateway(): void
    {
        Http::fake([
            '*/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => 'AUTH123'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->zarinpal()->create();

        $response = $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000,
            'payment_method_id' => $method->id,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('StartPay', $response->headers->get('Location'));
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'amount' => 150000,
            'purpose' => 'wallet_charge',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function choosing_card_to_card_goes_to_the_receipt_upload_page(): void
    {
        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create(); // default: card_to_card

        $response = $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000,
            'payment_method_id' => $method->id,
        ]);

        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $response->assertRedirect(route('website.wallet.receipt.show', $payment->id));
    }

    #[Test]
    public function a_customer_can_upload_a_receipt_for_their_own_pending_payment(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create();
        $this->actingAs($user)->post(route('website.wallet.charge.store'), [
            'amount' => 150000,
            'payment_method_id' => $method->id,
        ]);
        $payment = Payment::query()->where('user_id', $user->id)->firstOrFail();

        $response = $this->actingAs($user)->post(route('website.wallet.receipt.store', $payment->id), [
            'depositor_name' => 'علی رضایی',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $response->assertRedirect();
        $payment->refresh();
        $this->assertEquals('علی رضایی', $payment->depositor_name);
        $this->assertStringStartsWith('website:website-receipts/', $payment->receipt_image);
        Storage::disk('local')->assertExists(substr($payment->receipt_image, strlen('website:')));

        // پرداخت هم‌چنان pending می‌ماند — تایید نهایی فقط دست ادمین است.
        $this->assertEquals('pending', $payment->status);
    }

    #[Test]
    public function a_customer_cannot_upload_a_receipt_for_someone_elses_payment(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $method = PaymentMethod::factory()->create();
        $this->actingAs($owner)->post(route('website.wallet.charge.store'), [
            'amount' => 150000,
            'payment_method_id' => $method->id,
        ]);
        $payment = Payment::query()->where('user_id', $owner->id)->firstOrFail();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('website.wallet.receipt.show', $payment->id))
            ->assertNotFound();

        $this->actingAs($stranger)->post(route('website.wallet.receipt.store', $payment->id), [
            'depositor_name' => 'کسی دیگر',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertNotFound();

        $this->assertNull($payment->fresh()->receipt_image);
    }
}
