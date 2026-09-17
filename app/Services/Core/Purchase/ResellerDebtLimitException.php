<?php

namespace App\Services\Core\Purchase;

/**
 * بند ۲۰ بلوپرینت — خرید به‌دلیل رسیدن نماینده به سقف بدهی مسدود شد.
 *
 * زیرکلاس PurchaseNotAllowedException است تا هر جایی که فقط «خرید ممکن
 * نیست» را مدیریت می‌کند خودبه‌خود این را هم بگیرد، ولی مسیرهایی که
 * می‌خواهند به نماینده هشدار اعتبار بدهند بتوانند جدا تشخیصش دهند.
 */
class ResellerDebtLimitException extends PurchaseNotAllowedException {}
