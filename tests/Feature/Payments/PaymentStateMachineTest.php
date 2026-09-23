<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Core\Payments\InvalidPaymentTransitionException;
use App\Services\Core\Payments\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * فاز A۱ سند v2.1 (بند ۵۳) — PaymentStateMachine به‌عنوان تنها منبع
 * تعیین‌کننده‌ی گذارهای مجاز وضعیت پرداخت. این فایل خودِ ماشین حالت را
 * جدا از PaymentService تست می‌کند؛ سناریوهای «واقعی» (شارژ کیف‌پول،
 * Race، Audit) در PaymentServiceTest است.
 */
class PaymentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = app(PaymentStateMachine::class);
    }

    protected function paymentWithStatus(string $status): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'amount' => 100000,
            'purpose' => 'wallet_charge',
            'status' => $status,
            'wallet_owner_type' => 'user',
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function validTransitions(): array
    {
        return [
            'pending → confirmed' => ['pending', 'confirmed'],
            'pending → rejected' => ['pending', 'rejected'],
            'confirmed → refunded' => ['confirmed', 'refunded'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidTransitions(): array
    {
        return [
            'pending → refunded (نمی‌شود مستقیم بازگشت داد)' => ['pending', 'refunded'],
            'confirmed → confirmed (تایید دوباره)' => ['confirmed', 'confirmed'],
            'confirmed → rejected (تاییدشده دیگر قابل رد نیست)' => ['confirmed', 'rejected'],
            'rejected → confirmed (رد‌شده قابل تایید نیست)' => ['rejected', 'confirmed'],
            'rejected → rejected' => ['rejected', 'rejected'],
            'refunded → confirmed (وضعیت پایانی برگشت‌ناپذیر است)' => ['refunded', 'confirmed'],
            'refunded → refunded' => ['refunded', 'refunded'],
            'refunded → pending' => ['refunded', 'pending'],
        ];
    }

    #[Test]
    #[DataProvider('validTransitions')]
    public function it_allows_every_transition_the_document_lists(string $from, string $to): void
    {
        $payment = $this->paymentWithStatus($from);

        $this->assertTrue($this->machine->canTransition($from, $to));

        $result = $this->machine->transition($payment, $to);

        $this->assertEquals($to, $result->status);
        $this->assertEquals($to, $payment->fresh()->status);
    }

    #[Test]
    #[DataProvider('invalidTransitions')]
    public function it_rejects_every_transition_the_document_does_not_list(string $from, string $to): void
    {
        $payment = $this->paymentWithStatus($from);

        $this->assertFalse($this->machine->canTransition($from, $to));

        $this->expectException(InvalidPaymentTransitionException::class);

        try {
            $this->machine->transition($payment, $to);
        } finally {
            // مهم‌ترین بخش تست: یک گذار نامعتبر نباید حتی جزئی چیزی بنویسد
            $this->assertEquals($from, $payment->fresh()->status);
        }
    }

    #[Test]
    public function pending_and_confirmed_are_not_terminal_but_rejected_and_refunded_are(): void
    {
        $this->assertFalse($this->machine->isTerminal('pending'));
        $this->assertFalse($this->machine->isTerminal('confirmed'));
        $this->assertTrue($this->machine->isTerminal('rejected'));
        $this->assertTrue($this->machine->isTerminal('refunded'));
    }

    #[Test]
    public function extra_fields_are_persisted_atomically_with_the_status(): void
    {
        $payment = $this->paymentWithStatus('pending');

        $this->machine->transition($payment, 'confirmed', ['gateway_response' => ['ref' => 'x']]);

        $fresh = $payment->fresh();
        $this->assertEquals('confirmed', $fresh->status);
        $this->assertEquals(['ref' => 'x'], $fresh->gateway_response);
    }
}
