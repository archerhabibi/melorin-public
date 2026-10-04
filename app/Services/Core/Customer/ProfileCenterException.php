<?php

namespace App\Services\Core\Customer;

use RuntimeException;

/**
 * خطای اعتبارسنجی/قاعده‌ی Profile Center (B3.5). پیام فارسی و امن است (بدون جزئیات داخلی و بدون افشای
 * اینکه شماره/ایمیل مال چه کسی است)، پس هر Channel می‌تواند مستقیم نشانش دهد. `field` نام ورودیِ مسئول خطاست
 * (`full_name` | `phone` | `profile`) تا Website آن را زیر همان فیلد بگذارد.
 */
class ProfileCenterException extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
