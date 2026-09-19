<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * پس از تأیید پرداخت (شارژ کیف‌پول) منتشر می‌شود؛ PaymentService نباید
 * بداند هر کانال چه اعلانی بدهد. هر کانال Listener خودش را ثبت می‌کند
 * (مثلاً اعلان تلگرام).
 */
class PaymentConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Payment $payment) {}
}
