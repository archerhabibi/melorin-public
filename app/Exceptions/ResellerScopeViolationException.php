<?php

namespace App\Exceptions;

use Exception;

/**
 * طبق «اصل طلایی امنیت و جداسازی داده» (سند نیازمندی Reseller
 * Platform، بند ۲): هر نماینده فقط باید داده‌ها و عملیات متعلق به
 * خودش را ببیند. این Exception تنها راهی است که یک تلاش برای عبور از
 * Scope یک نماینده (مثلاً assign کردن مشتریِ نماینده‌ی دیگر، یا حذف
 * مشتری‌ای که اصلاً مال این نماینده نیست) گزارش می‌شود — عمداً از
 * Exceptionهای عمومی (\RuntimeException و ...) استفاده نمی‌شود تا در
 * تست‌های Isolation بتوان دقیقاً همین نوع خطا را assert کرد.
 */
class ResellerScopeViolationException extends Exception
{
    public function __construct(string $message = 'این عملیات خارج از دامنه‌ی دسترسی این نماینده است.')
    {
        parent::__construct($message);
    }
}
