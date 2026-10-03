<?php

namespace App\Services\Core\Payments;

use RuntimeException;

/**
 * Callback درگاه با پرداخت هدف مطابقت ندارد (Authority نادرست/خالی).
 * هیچ تغییر وضعیت یا مالی‌ای انجام نشده است؛ فراخواننده باید 404 عمومی بدهد.
 */
class InvalidGatewayCallbackException extends RuntimeException {}
