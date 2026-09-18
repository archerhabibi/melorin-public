<?php

namespace App\Exceptions;

use App\Services\Core\Purchase\PurchaseNotAllowedException;

/**
 * طبق «اصل طلایی امنیت و جداسازی داده» (سند نیازمندی Reseller
 * Platform، بند ۲) و بند ۱۶ سند قیمت‌گذاری: هر عملیات باید در Context
 * خودش بماند. این Exception تنها راهی است که عبور از Scope یک نماینده
 * گزارش می‌شود — چه در سرویس‌های مدیریتی (assign/remove مشتری) و چه در
 * خودِ مسیر خرید، وقتی CustomerAccountِ فروشگاه A بخواهد از فروشگاه B
 * خرید کند.
 *
 * زیرکلاس PurchaseNotAllowedException است تا نقض Scope در مسیر خرید،
 * برای هر کدی که «خرید ممکن نیست» را مدیریت می‌کند، یک حالت شناخته‌شده
 * باشد نه یک خطای ناشناخته‌ی ۵۰۰.
 */
class ResellerScopeViolationException extends PurchaseNotAllowedException
{
    public function __construct(string $message = 'این عملیات خارج از دامنه‌ی دسترسی این نماینده است.')
    {
        parent::__construct($message);
    }
}
