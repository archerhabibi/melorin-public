<?php

namespace App\Services\Core\Customer;

use App\Models\Payment;
use Carbon\CarbonInterface;

/**
 * یک ردیف از «شارژهای اخیر» (Payment با purpose=wallet_charge) به زبان مشتری (B3.3).
 *
 * وضعیت‌های واقعی Payment فقط pending/confirmed/rejected/refunded‌اند؛ مشتری برای `pending` باید بداند
 * «منتظر کدام کار» است — خودش باید رسید بفرستد، ادمین باید بررسی کند، یا درگاه هنوز جواب نداده.
 * این تفکیک فقط نمایشی/مشتق است و هیچ وضعیتی را در DB تغییر نمی‌دهد.
 */
final class WalletChargeEntry
{
    public const AWAITING_RECEIPT = 'awaiting_receipt';

    public const UNDER_REVIEW = 'under_review';

    public const AWAITING_GATEWAY = 'awaiting_gateway';

    public const ABANDONED = 'abandoned';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const REFUNDED = 'refunded';

    public function __construct(
        public readonly int $paymentId,
        public readonly int $amount,
        public readonly string $state,
        public readonly string $methodName,
        public readonly CarbonInterface $createdAt,
    ) {}

    public static function fromPayment(Payment $payment, int $gatewayStaleHours): self
    {
        $manual = ($payment->paymentMethod->type ?? null) === 'card_to_card';

        $state = match ($payment->status) {
            'confirmed' => self::CONFIRMED,
            'rejected' => self::REJECTED,
            'refunded' => self::REFUNDED,
            default => match (true) {
                $manual && empty($payment->receipt_image) => self::AWAITING_RECEIPT,
                $manual => self::UNDER_REVIEW,
                $payment->created_at->lt(now()->subHours($gatewayStaleHours)) => self::ABANDONED,
                default => self::AWAITING_GATEWAY,
            },
        };

        return new self(
            (int) $payment->id,
            (int) $payment->amount,
            $state,
            (string) ($payment->paymentMethod->name ?? '—'),
            $payment->created_at,
        );
    }

    /** مشتری می‌تواند همین الان رسید بفرستد (همان شرط `Payment::findPendingForReceipt` + بدون رسید قبلی) */
    public function canUploadReceipt(): bool
    {
        return $this->state === self::AWAITING_RECEIPT;
    }

    /** منتظر تصمیم/نتیجه (در شمارنده‌ی «در انتظار» می‌آید) */
    public function isPending(): bool
    {
        return in_array($this->state, [self::AWAITING_RECEIPT, self::UNDER_REVIEW, self::AWAITING_GATEWAY], true);
    }

    /** فقط برای نمایش (هم‌الگو با `WalletTransaction::typeLabels()`) */
    public function label(): string
    {
        return match ($this->state) {
            self::AWAITING_RECEIPT => 'در انتظار ثبت رسید',
            self::UNDER_REVIEW => 'در حال بررسی',
            self::AWAITING_GATEWAY => 'در انتظار نتیجه‌ی درگاه',
            self::ABANDONED => 'ناتمام',
            self::CONFIRMED => 'تأیید شد',
            self::REJECTED => 'ناموفق',
            self::REFUNDED => 'بازگشت داده شد',
            default => $this->state,
        };
    }

    /** @return 'success'|'warning'|'danger'|'info'|'neutral' */
    public function tone(): string
    {
        return match ($this->state) {
            self::CONFIRMED => 'success',
            self::AWAITING_RECEIPT => 'warning',
            self::UNDER_REVIEW, self::AWAITING_GATEWAY => 'info',
            self::REJECTED => 'danger',
            default => 'neutral',
        };
    }
}
