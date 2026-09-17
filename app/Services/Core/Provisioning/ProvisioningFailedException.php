<?php

namespace App\Services\Core\Provisioning;

use RuntimeException;

/**
 * ساخت اکانت روی پنل VPN شکست خورد (بند ۲۵ و ۲۹ بلوپرینت).
 *
 * نکته‌ی مهم: این استثنا یعنی «سرویس تحویل نشد» و هیچ‌وقت به معنای
 * «پول کسر نشد» نیست — تسویه‌ی مالی پیش از این نقطه و در تراکنش جدا
 * انجام شده. وضعیت مالی سفارش را باید از خودِ Order خواند.
 */
class ProvisioningFailedException extends RuntimeException {}
