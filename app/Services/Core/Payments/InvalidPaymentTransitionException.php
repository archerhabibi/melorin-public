<?php

namespace App\Services\Core\Payments;

use RuntimeException;

/**
 * تلاش برای یک گذار غیرمجاز در ماشین حالت پرداخت (بند ۱۸ بلوپرینت) —
 * مثلاً تأیید دوباره‌ی پرداختی که قبلاً بازگشت خورده.
 */
class InvalidPaymentTransitionException extends RuntimeException {}
