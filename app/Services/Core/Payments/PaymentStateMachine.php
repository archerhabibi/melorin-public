<?php

namespace App\Services\Core\Payments;

use App\Models\Payment;
/**
 * بند ۱۸ بلوپرینت — «Transitionها باید مشخص باشند. Transition نامعتبر
 * نباید امکان‌پذیر باشد.»
 *
 * امروز وضعیت پرداخت هرجا لازم بوده با یک update ساده عوض شده. نتیجه
 * این است که هیچ چیزی جلوی یک پرداختِ رد شده را نمی‌گیرد که دوباره
 * confirmed شود، یا یک پرداخت بازگشت‌خورده دوباره تأیید شود و کیف‌پول
 * را دوباره شارژ کند.
 *
 * گذارهای مجاز:
 *
 *     pending  ──→ confirmed
 *     pending  ──→ rejected
 *     confirmed ─→ refunded
 *
 * هر چیز دیگری (از جمله confirmed → confirmed) نامعتبر است.
 */
class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    protected const TRANSITIONS = [
        'pending' => ['confirmed', 'rejected'],
        'confirmed' => ['refunded'],
        'rejected' => [],
        'refunded' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, static::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws InvalidPaymentTransitionException
     */
    public function assertCanTransition(Payment $payment, string $to): void
    {
        $from = $payment->status;

        if (! $this->canTransition($from, $to)) {
            throw new InvalidPaymentTransitionException(
                "گذار نامعتبر وضعیت پرداخت #{$payment->id}: از «{$from}» به «{$to}»."
            );
        }
    }

    /**
     * وضعیت را عوض می‌کند، فقط اگر گذار مجاز باشد.
     *
     * توجه: این متد عمداً خودش قفل یا تراکنش نمی‌گیرد. دلیلش این است که
     * فراخواننده (PaymentService::finalize) از قبل رکورد را با
     * lockForUpdate داخل تراکنش گرفته؛ گرفتن قفل دوباره اینجا یا
     * بی‌فایده است یا بدتر، منشأ deadlock. مسئولیت قفل جایی است که
     * تراکنش شروع می‌شود.
     */
    public function transition(Payment $payment, string $to, array $extra = []): Payment
    {
        $this->assertCanTransition($payment, $to);

        $payment->update(array_merge($extra, ['status' => $to]));

        return $payment;
    }

    /** وضعیت‌هایی که دیگر هیچ گذاری از آن‌ها ممکن نیست */
    public function isTerminal(string $status): bool
    {
        return (static::TRANSITIONS[$status] ?? []) === [];
    }
}
