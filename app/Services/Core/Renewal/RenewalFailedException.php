<?php

namespace App\Services\Core\Renewal;

use App\Services\Core\Provisioning\Concerns\CarriesFailureOutcome;
use RuntimeException;

/**
 * تمدید روی پنل شکست خورد (بند ۲۹ بلوپرینت).
 *
 * مثل ProvisioningFailedException، این هرگز به معنای «پول کسر نشد»
 * نیست — تسویه‌ی مالی پیش از این نقطه انجام شده و سفارش در وضعیت
 * provision_failed باقی می‌ماند تا قابل پیگیری و جبران باشد.
 */
class RenewalFailedException extends RuntimeException
{
    use CarriesFailureOutcome;
}
