<?php

namespace App\Services\Core\Customer;

use RuntimeException;

/**
 * خطای قابل‌نمایش به مشتری در Ticket Center (B3.4). پیام فارسی و امن است (بدون جزئیات داخلی)،
 * پس Channel می‌تواند مستقیم نشانش دهد.
 */
class TicketCenterException extends RuntimeException {}
