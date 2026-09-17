<?php

namespace App\Services\Core\Purchase;

use RuntimeException;

/**
 * هر دلیلی که PurchaseGuard (بند ۱۵ بلوپرینت) خرید را رد می‌کند.
 * پیام این استثنا مستقیماً به کاربر نشان داده می‌شود، پس باید همیشه
 * فارسی و قابل‌فهم باشد — نه جزئیات فنی.
 */
class PurchaseNotAllowedException extends RuntimeException {}
